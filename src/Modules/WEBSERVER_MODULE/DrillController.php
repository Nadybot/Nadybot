<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\Promise\{timeout};
use function Amp\{asyncCall, call, delay};
use function Safe\preg_replace;

use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Http\Client\{HttpClientBuilder, HttpException};
use Amp\Socket\ConnectContext;
use Amp\Websocket\Client\{Connection, ConnectionException, Handshake, Rfc6455Connector};
use Amp\Websocket\{ClosedException, Message};
use Amp\{Deferred, Promise, TimeoutException};
use Generator;

use Nadybot\Core\{AOChatEvent, Attributes as NCA, ConfigFile, EventManager, LoggerWrapper, ModuleInstance, Registry, StopExecutionException, UserException};
use Throwable;

#[NCA\ProvidesEvent("drill(*)")]
#[NCA\Instance]
class DrillController extends ModuleInstance {
	public const OFF = "off";

	/** Service to make the webserver publicly accessible */
	#[NCA\Setting\Text(
		options: [
			"off" => self::OFF,
			"US-based" => "wss://drill.us.nadybot.org",
			"EU-based" => "wss://drill.nadybot.org",
		]
	)]
	public string $drillServer=self::OFF;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	private ?Connection $client=null;
	private int $reconnectDelay = 5;

	/** @var array<string,Drill\Connection> */
	private array $handlers = [];

	#[NCA\Event(
		name: "connect",
		description: "Connect to Drill server",
	)]
	public function connectToDrill(): Generator {
		if ($this->drillServer === self::OFF) {
			return;
		}
		yield $this->connect();
	}

	#[NCA\SettingChangeHandler("drill_server")]
	public function switchDrill(string $setting, string $old, string $new): void {
		if ($new !== self::OFF && !preg_match("/^wss?:\/\//", $new)) {
			throw new UserException("<highlight>{$new}<end> is not a valid Drill-server");
		}
		asyncCall(function () use ($new): Generator {
			if (isset($this->client)) {
				yield $this->client->close();
			}
			if ($new === self::OFF) {
				return;
			}
			yield $this->connect($new);
		});
	}

	/** @return Promise<void> */
	public function connect(?string $url=null): Promise {
		return call(function () use ($url): Generator {
			$url ??= $this->drillServer;
			$handshake = new Handshake($url);
			$connectContext = (new ConnectContext())->withTcpNoDelay();
			$httpClient = (new HttpClientBuilder())
				->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
				->intercept(new RemoveRequestHeader('origin'))
				->build();
			$client = new Rfc6455Connector($httpClient);
			try {
				$this->logger->info("Connecting to Drill server {$url}");

				/** @var Connection */
				$connection = yield $client->connect($handshake, null);
				$this->client = $connection;
				$event = new DrillEvent();
				$event->type = "drill(connect)";
				$event->client = $connection;
				$this->eventManager->fireEvent($event);
				$this->logger->info("Connected to Drill server {$url}");
				while ($message = yield $connection->receive()) {
					/** @var Message $message */
					$payload = yield $message->buffer();

					/** @var string $payload */
					$this->processWebsocketMessage($connection, $payload);
				}
				if ($this->client->isClosedByPeer()) {
					throw new ClosedException(
						"Drill unexpectedly closed the connection",
						$this->client->getCloseCode(),
						$this->client->getCloseReason(),
					);
				}
			} catch (ConnectionException $e) {
				$this->logger->error("Still endpoint errored: {error}", [
					"error" => $e->getMessage(),
				]);
				return;
			} catch (HttpException $e) {
				$this->logger->error("Request to connect to Drill failed: {error}", [
					"error" => $e->getMessage(),
				]);
				return;
			} catch (ClosedException $e) {
				$this->logger->notice("Reconnecting to Drill in {$this->reconnectDelay}s.");
				yield delay($this->reconnectDelay * 1000);
				$this->reconnectDelay = max($this->reconnectDelay * 2, 5);
				yield $this->connect();
			} finally {
				$this->client = null;
			}
			$this->logger->notice("Connection to {url} successfully closed.", [
				"url" => $url,
			]);
		});
	}

	public function processWebsocketMessage(Connection $client, string $msg): void {
		try {
			$packet = Drill\PacketFactory::parse($msg);
		} catch (Drill\UnsupportedPacketException $e) {
			$this->logger->warning("Received unsupported Drill package type {type}", [
				"type" => $e->getMessage(),
			]);
			return;
		}
		$this->logger->debug("Received Drill-package {type}", [
			"type" => get_class($packet),
		]);
		$event = new DrillPacketEvent();

		/** @var string */
		$kebabCase = preg_replace(
			"/([a-z])([A-Z])/",
			'$1-$2',
			class_basename($packet)
		);
		$event->type = "drill(" . strtolower($kebabCase) . ")";
		$event->client = $client;
		$event->packet = $packet;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: "drill(hello)",
		description: "Choose Drill authentication",
	)]
	public function chooseDrillAuth(DrillPacketEvent $event): Generator {
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\Hello);
		$this->logger->notice(
			"Connected to Drill-server {url} running Drill protocol v{proto}: {greeting}",
			[
				"proto" => $packet->protoVersion,
				"url" => $this->drillServer,
				"greeting" => $packet->description,
			]
		);
		if ($packet->authMode !== Drill\Auth::AO_TELL) {
			$this->logger->error("Drill server doesn't support AO authentication");
			yield $event->client->close();
			return;
		}
		if ($packet->protoVersion !== 1) {
			$this->logger->error("Drill server runs unsupported protocol version");
			yield $event->client->close();
			return;
		}
		$answer = new Drill\Packet\AoAuth(characterName: $this->config->name);
		Registry::injectDependencies($answer);
		yield $answer->send($event->client);
	}

	#[NCA\Event(
		name: "drill(token-in-ao-tell)",
		description: "Handle Drill authentication",
	)]
	public function authenticateDrill(DrillPacketEvent $event): Generator {
		$deferred = new Deferred();
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\TokenInAoTell);
		$resolver = function (AOChatEvent $eventObj) use ($packet, $deferred): void {
			if ($eventObj->sender !== $packet->sender) {
				return;
			}
			if (!$deferred->isResolved()) {
				$deferred->resolve($eventObj->message);
				throw new StopExecutionException();
			}
		};
		$this->eventManager->subscribe("msg", $resolver);
		$this->logger->info("Waiting 30s for token from {sender}", [
			"sender" => $packet->sender,
		]);

		try {
			/** @var string */
			$code = yield timeout($deferred->promise(), 30000);

			/** @var string */
			$code = preg_replace("/^!drill\s+/", "", $code);
			$this->logger->info("Drill-code received: {code}", [
				"code" => $code,
			]);
		} catch (TimeoutException $e) {
			$this->logger->warning("No Drill auth token from {sender} received for 30s", [
				"sender" => $packet->sender,
			]);
			return;
		} catch (Throwable $e) {
			$this->logger->warning("Error waiting for Drill auth token", [
				"exception" => $e,
			]);
			return;
		} finally {
			$this->eventManager->unsubscribe("msg", $resolver);
		}
		$answer = new Drill\Packet\PresentToken(
			token: $code,
			desiredSudomain: strtolower($this->config->name)
		);
		Registry::injectDependencies($answer);
		yield $answer->send($event->client);
	}

	#[NCA\Event(
		name: "drill(lets-go)",
		description: "Activate Drill",
	)]
	public function activateDrill(DrillPacketEvent $event): void {
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\LetsGo);
		$this->logger->notice("This bot is now exposed via {url}", [
			"url" => $packet->publicUrl,
		]);
	}

	#[NCA\Event(
		name: "drill(data)",
		description: "Handle Drill data",
	)]
	public function receiveData(DrillPacketEvent $event): Generator {
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\Data);
		$this->logger->info("Number of active clients: {num_conn}", [
			"num_conn" => count(array_keys($this->handlers)),
		]);
		$this->logger->debug("Received data for UUID {uuid}: {data}", [
			"uuid" => $packet->uuid,
			"data" => $packet->data,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			$this->logger->info('New client connected via Drill');
			$handler = new Drill\Connection(
				$packet->uuid,
				$event->client
			);
			Registry::injectDependencies($handler);
			$success = yield $handler->loop();
			if (!$success) {
				$this->logger->notice("Drill error connecting to local webserver, sending 502");
				$http = "HTTP/1.1 502\r\n".
					"Content-Length: 0\r\n".
					"\r\n";
				$errReply = new Drill\Packet\Data(uuid: $packet->uuid, data: $http);
				Registry::injectDependencies($errReply);
				yield $errReply->send($event->client);
				$closeReply = new Drill\Packet\Closed(uuid: $packet->uuid);
				Registry::injectDependencies($closeReply);
				yield $closeReply->send($event->client);
				return;
			}
			$this->handlers[$packet->uuid] = $handler;
		}
		yield $this->handlers[$packet->uuid]->handle($packet);
	}

	#[NCA\Event(
		name: "drill(closed)",
		description: "Handle Drill disconnect",
	)]
	public function clientDisconnect(DrillPacketEvent $event): void {
		$packet = $event->packet;
		assert($packet instanceof Drill\Packet\Closed);
		$this->logger->info("Drill received disconnect for UUID {uuid}", [
			"uuid" => $packet->uuid,
		]);

		if (!isset($this->handlers[$packet->uuid])) {
			return;
		}
		$this->handlers[$packet->uuid]->handleDisconnect();
		unset($this->handlers[$packet->uuid]);
	}

	#[NCA\Event(
		name: "drill(disallowed-packet)",
		description: "Handle disallowed packets",
	)]
	public function handleDisallowedPacket(): void {
		$this->logger->warning("Drill server complains about disallowed packet");
	}

	#[NCA\Event(
		name: "drill(auth-failed)",
		description: "Handle failed authentication",
	)]
	public function handleAuthFailed(): void {
		$this->logger->notice("Failed to authenticate to the Drill server. Retrying.");
	}

	#[NCA\Event(
		name: "drill(out-of-capacity)",
		description: "Handle Drill-server full error",
	)]
	public function handleOOC(): void {
		$this->logger->warning("Drill server currently doesn't have any capacity for this bot. Retrying.");
	}
}
