<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\delay;
use function Safe\preg_match;
use Amp\Http\Client\HttpException;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\WebsocketClosedException;
use Amp\{CancelledException, DeferredFuture, TimeoutCancellation};

use Nadybot\Core\Drill\{DrillAuthMode, DrillConnection, DrillConnector, DrillHttpConnection};
use Nadybot\Core\Events\{ConnectEvent, RecvMsgEvent};
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	Drill,
	EventManager,
	Exceptions\StopExecutionException,
	Exceptions\UserException,
	ModuleInstance,
	Safe,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

#[NCA\ProvidesEvent(DrillPacketEvent::class)]
#[NCA\Instance]
class DrillController extends ModuleInstance {
	public const OFF = 'off';

	/** Service to make the webserver publicly accessible */
	#[NCA\Setting\Text(
		options: [
			'off' => self::OFF,
			'US-based' => 'wss://drill.us.nadybot.org',
			'EU-based' => 'wss://drill.nadybot.org',
		]
	)]
	public string $drillServer=self::OFF;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private WebserverController $wsCtrl;

	private ?DrillConnection $connection=null;
	private int $reconnectDelay = 5;

	/** @var array<string,DrillHttpConnection> */
	private array $handlers = [];

	#[NCA\Event(
		name: ConnectEvent::EVENT_MASK,
		description: 'Connect to Drill server',
	)]
	public function connectToDrill(): void {
		if ($this->drillServer === self::OFF) {
			return;
		}
		EventLoop::queue($this->connect(...));
	}

	#[NCA\SettingChangeHandler('drill_server')]
	public function switchDrill(string $setting, string $old, string $new): void {
		if ($new !== self::OFF && !preg_match("/^wss?:\/\//", $new)) {
			throw new UserException("<highlight>{$new}<end> is not a valid Drill-server");
		}
		if (isset($this->connection)) {
			$this->connection->close();
			$this->connection = null;
		}
		if ($new === self::OFF) {
			return;
		}
		EventLoop::queue($this->connect(...), $new);
	}

	public function connect(?string $url=null): void {
		$url ??= $this->drillServer;
		$client = new DrillConnector(uri: $url, logger: $this->logger);
		try {
			$connection = $client->connect();
			$this->connection = $connection;
			$event = new DrillConnectEvent(connection: $connection);
			$this->eventManager->fireEvent($event);
			while (null !== ($packet = $connection->receive())) {
				$event = new DrillPacketEvent(
					connection: $connection,
					packet: $packet,
				);
				$this->eventManager->fireEvent($event);
			}
		} catch (WebsocketConnectException $e) {
			delay($this->reconnectDelay);
			$this->reconnectDelay = max($this->reconnectDelay * 2, 5);
			if ($this->drillServer !== self::OFF) {
				$this->connect();
			}
			return;
		} catch (HttpException $e) {
			$this->logger->error('Request to connect to Drill failed: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			delay($this->reconnectDelay);
			$this->reconnectDelay = max($this->reconnectDelay * 2, 5);
			if ($this->drillServer !== self::OFF) {
				$this->connect();
			}
			return;
		} catch (WebsocketClosedException $e) {
			$this->logger->notice('Reconnecting to Drill in {delay}s.', [
				'delay' => $this->reconnectDelay,
				'exception' => $e,
			]);
			delay($this->reconnectDelay);
			$this->reconnectDelay = max($this->reconnectDelay * 2, 5);
			if ($this->drillServer !== self::OFF) {
				$this->connect();
			}
			return;
		} finally {
			$this->connection = null;
		}
		$this->logger->notice('Connection to {url} successfully closed.', [
			'url' => $url,
		]);
	}

	#[NCA\Event(
		name: 'drill(hello)',
		description: 'Choose Drill authentication',
	)]
	public function chooseDrillAuth(DrillPacketEvent $event): void {
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\Hello);
		$this->logger->notice(
			'Connected to Drill-server {url} running Drill protocol v{proto}: {greeting}',
			[
				'proto' => $packet->protoVersion,
				'url' => $this->drillServer,
				'greeting' => $packet->description,
			]
		);
		if ($packet->authMode !== DrillAuthMode::AO_TELL) {
			$this->logger->error("Drill server doesn't support AO authentication");
			$event->connection->close();
			return;
		}
		if ($packet->protoVersion !== 1) {
			$this->logger->error('Drill server runs unsupported protocol version');
			$event->connection->close();
			return;
		}
		$answer = new Drill\Packet\AoAuth(characterName: $this->config->main->character);
		$event->connection->send($answer);
	}

	#[NCA\Event(
		name: 'drill(token-in-ao-tell)',
		description: 'Handle Drill authentication',
	)]
	public function authenticateDrill(DrillPacketEvent $event): void {
		/** @var DeferredFuture<string> */
		$deferred = new DeferredFuture();
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\TokenInAoTell);
		$resolver = static function (RecvMsgEvent $eventObj) use ($packet, $deferred): void {
			if ($eventObj->sender !== $packet->sender) {
				return;
			}
			if (!$deferred->isComplete()) {
				$deferred->complete($eventObj->message);
				throw new StopExecutionException();
			}
		};
		$this->eventManager->subscribe('msg', $resolver);
		$this->logger->info('Waiting 30s for token from {sender}', [
			'sender' => $packet->sender,
		]);

		$future = $deferred->getFuture();
		try {
			$code = $future->await(new TimeoutCancellation(30));

			/** @var string */
			$code = Safe::pregReplace("/^!drill\s+/", '', $code);
			$this->logger->info('Drill-code received: {code}', [
				'code' => $code,
			]);
		} catch (CancelledException $e) {
			$this->logger->warning('No Drill auth token from {sender} received for 30s', [
				'sender' => $packet->sender,
				'exception' => $e,
			]);
			return;
		} catch (Throwable $e) {
			$this->logger->warning('Error waiting for Drill auth token', [
				'exception' => $e,
			]);
			return;
		} finally {
			$this->eventManager->unsubscribe('msg', $resolver);
		}
		$answer = new Drill\Packet\PresentToken(
			token: $code,
			desiredSudomain: strtolower($this->config->main->character)
		);
		$event->connection->send($answer);
	}

	#[NCA\Event(
		name: 'drill(lets-go)',
		description: 'Activate Drill',
	)]
	public function activateDrill(DrillPacketEvent $event): void {
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\LetsGo);
		$this->logger->notice('This bot is now exposed via {url}', [
			'url' => $packet->publicUrl,
		]);
	}

	#[NCA\Event(
		name: 'drill(data)',
		description: 'Handle Drill data',
	)]
	public function receiveData(DrillPacketEvent $event): void {
		$packet = $event->packet;
		if (!($packet instanceof Drill\Packet\Data)) {
			return;
		}
		$this->logger->info('Number of active clients: {num_conn}', [
			'num_conn' => count(array_keys($this->handlers)),
		]);
		$this->logger->debug('Received data for UUID {uuid}: {data}', [
			'uuid' => $packet->uuid,
			'data' => $packet->data,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			$this->logger->info('New client connected via Drill');
			$handler = new DrillHttpConnection(
				uuid: $packet->uuid,
				host: '127.0.0.1',
				port: $this->wsCtrl->webserverPort,
				drillConnection: $event->connection,
				logger: $this->logger,
			);
			$success = $handler->loop();
			if (!$success) {
				$this->logger->notice('Drill error connecting to local webserver, sending 502');
				$http = "HTTP/1.1 502\r\n".
					"Content-Length: 0\r\n".
					"\r\n";
				$errReply = new Drill\Packet\Data(uuid: $packet->uuid, data: $http);
				$event->connection->send($errReply);
				$closeReply = new Drill\Packet\Closed(uuid: $packet->uuid);
				$event->connection->send($closeReply);
				return;
			}
			$this->handlers[$packet->uuid] = $handler;
		}
		$this->handlers[$packet->uuid]->handle($packet);
	}

	#[NCA\Event(
		name: 'drill(closed)',
		description: 'Handle Drill disconnect',
	)]
	public function clientDisconnect(DrillPacketEvent $event): void {
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\Closed);
		$this->logger->info('Drill received disconnect for UUID {uuid}', [
			'uuid' => $packet->uuid,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			return;
		}
		$this->handlers[$packet->uuid]->handleDisconnect();
		unset($this->handlers[$packet->uuid]);
	}

	#[NCA\Event(
		name: 'drill(disallowed-packet)',
		description: 'Handle disallowed packets',
	)]
	public function handleDisallowedPacket(): void {
		$this->logger->warning('Drill server complains about disallowed packet');
	}

	#[NCA\Event(
		name: 'drill(auth-failed)',
		description: 'Handle failed authentication',
	)]
	public function handleAuthFailed(): void {
		$this->logger->notice('Failed to authenticate to the Drill server. Retrying.');
	}

	#[NCA\Event(
		name: 'drill(out-of-capacity)',
		description: 'Handle Drill-server full error',
	)]
	public function handleOOC(): void {
		$this->logger->warning("Drill server currently doesn't have any capacity for this bot. Retrying.");
	}
}
