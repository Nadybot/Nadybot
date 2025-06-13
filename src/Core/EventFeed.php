<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\delay;
use function Safe\json_decode;
use Amp\Http\Client\{
	Connection\DefaultConnectionFactory,
	Connection\UnlimitedConnectionPool,
	HttpClientBuilder,
	Interceptor\RemoveRequestHeader,
};
use Amp\Socket\ConnectContext;
use Amp\Websocket\Client\{Rfc6455ConnectionFactory, Rfc6455Connector, WebsocketConnectException, WebsocketHandshake};
use Amp\Websocket\{PeriodicHeartbeatQueue, WebsocketCloseCode, WebsocketClosedException};
use Nadybot\Core\{
	Attributes as NCA,
	Events\EventFeedConnect,
	Events\EventFeedReconnect,
	Types\EventFeedHandler,
};
use Nadybot\Core\Events\EventFeed\{
	ErrorPackageEvent,
	HelloPackageEvent,
	JoinPackageEvent,
	LeavePackageEvent,
	MessagePackageEvent,
	ResultPackageEvent,
	RoomInfoPackageEvent,
	SuccessPackageEvent
};
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Revolt\EventLoop;
use Safe\Exceptions\JsonException;
use Throwable;

/**
 * This class is the interface to the public highway channels
 *
 * @author Nadyita
 */
#[NCA\Instance]
class EventFeed {
	public const URI = 'wss://ws.nadybot.org';
	public const RECONNECT_DELAY = 5;

	/**
	 * The event feed handlers for each Highway room
	 *
	 * @var array<string,list<EventFeedHandler>>
	 */
	private array $roomHandlers = [];

	private ?Highway\Connection $connection=null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $clientBuilder;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventManager $eventManager;

	private bool $isReconnect = false;

	/**
	 * Rooms that we successfully joined
	 *
	 * @var array<string,true>
	 */
	private array $attachedRooms = [];

	/**
	 * Rooms provided by the Highway server
	 *
	 * @var array<string,true>
	 */
	private array $availableRooms = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->eventManager->subscribe(HelloPackageEvent::class, $this->handleHello(...));
		$this->eventManager->subscribe(ErrorPackageEvent::class, $this->handleError(...));
		$this->eventManager->subscribe(SuccessPackageEvent::class, $this->handleSuccess(...));
		$this->eventManager->subscribe(RoomInfoPackageEvent::class, $this->handleRoomInfo(...));
		$this->eventManager->subscribe(MessagePackageEvent::class, $this->handleMessage(...));

		$instances = Registry::getAllInstances();
		foreach ($instances as $instance) {
			$refClass = new ReflectionClass($instance);
			$refAttributes = $refClass->getAttributes(NCA\HandlesEventFeed::class);
			foreach ($refAttributes as $refAttribute) {
				$attribute = $refAttribute->newInstance();
				if ($instance instanceof EventFeedHandler) {
					$this->registerEventFeedHandler($attribute->room, $instance);
				}
			}
		}
	}

	/**
	 * Are we connected to the Highway server?
	 *
	 * @psalm-assert-if-true Highway\Connection $this->connection
	 */
	public function isConnected(): bool {
		return isset($this->connection);
	}

	/** Get the highway connection or `null` if we're not connected */
	public function getHighwayConnection(): ?Highway\Connection {
		return $this->connection;
	}

	/** Register an `EventFeedHandler` for a Highway room */
	public function registerEventFeedHandler(string $room, EventFeedHandler $handler): void {
		$this->roomHandlers[$room] ??= [];
		$this->roomHandlers[$room] []= $handler;
		$this->logger->info('New event feed handler for {room}: {handler}', [
				'room' => $room,
				'handler' => $handler,
		]);
		if (!isset($this->connection)) {
			$this->logger->info('Not connected to {server} - not joining "{room}" now', [
				'server' => self::URI,
				'room' => $room,
			]);
			return;
		}
		if (!isset($this->availableRooms[$room])) {
			$this->logger->notice('Room "{room}" not found on {server}. Available: {rooms}', [
				'server' => self::URI,
				'room' => $room,
				'rooms' => array_keys($this->availableRooms),
			]);
			return;
		}
		EventLoop::queue(function () use ($room): void {
			$announcer = function (RoomInfoPackageEvent $event) use ($room, &$announcer): void {
				$package = $event->getPackage();
				if ($package->room === $room) {
					$this->logger->notice('Global event feed attached to {room}', [
						'room' => $room,
					]);
					$this->eventManager->unsubscribe(RoomInfoPackageEvent::class, $announcer);
				}
			};
			$this->eventManager->subscribe(RoomInfoPackageEvent::class, $announcer);
			if (isset($this->connection)) {
				$joinPackage = new Highway\Out\Join(room: $room);
				$this->connection->send($joinPackage);
			}
		});
	}

	/** Remove a registered `EventFeedHandler` from a Highway room */
	public function unregisterEventFeedHandler(string $room, EventFeedHandler $handler): void {
		if (!isset($this->roomHandlers[$room])) {
			return;
		}
		if (!in_array($handler, $this->roomHandlers[$room], true)) {
			return;
		}
		$newHandlers = [];
		foreach ($this->roomHandlers[$room] as $roomHandler) {
			if ($roomHandler !== $handler) {
				$newHandlers []= $roomHandler;
			}
		}
		$this->roomHandlers[$room] = $newHandlers;
		$this->logger->info('Removed event feed handler for "{room}": {handler}', [
				'room' => $room,
				'handler' => $handler,
		]);
		if (!isset($this->connection) || count($this->roomHandlers[$room])) {
			return;
		}
		if (!isset($this->availableRooms[$room])) {
			$this->logger->notice('Room "{room}" not found on {server}. Available: {rooms}', [
				'server' => self::URI,
				'room' => $room,
				'rooms' => array_keys($this->availableRooms),
			]);
			return;
		}
		EventLoop::queue(function () use ($room): void {
			$leavePackage = new Highway\Out\Leave(room: $room);
			$announcer = function (SuccessPackageEvent $event) use ($room, &$announcer): void {
				$this->logger->notice('Global event feed detached from {room}', [
					'room' => $room,
				]);
				$this->eventManager->unsubscribe(SuccessPackageEvent::class, $announcer);
				unset($this->attachedRooms[$room]);
			};
			$this->eventManager->subscribe(SuccessPackageEvent::class, $announcer);
			if (isset($this->connection)) {
				$this->connection->send($leavePackage);
			}
		});
	}

	/** Start connecting and processing packages in an endless loop */
	public function mainLoop(): void {
		if (BotRunner::getArguments()->testRun) {
			$this->logger->warning('Disabling event feed during test runs');
			return;
		}
		EventLoop::queue(function (): void {
			while ($this->singleLoop()) {
				delay(self::RECONNECT_DELAY);
			}
		});
	}

	/** Connect to the Highway server */
	protected function connect(): ?Highway\Connection {
		$connectionFactory = new Rfc6455ConnectionFactory(
			heartbeatQueue: new PeriodicHeartbeatQueue(
				heartbeatPeriod: 5, // 5 seconds
			),
		);

		$handshake = (new WebsocketHandshake(self::URI))
			->withTcpConnectTimeout(3_000)
			->withTlsHandshakeTimeout(3_000);
		$connectContext = (new ConnectContext())->withTcpNoDelay();
		$httpClientBuilder = $this->clientBuilder
			->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
			->intercept(new RemoveRequestHeader('origin'));
		$httpClient = $httpClientBuilder->build();
		$wsClient = new Rfc6455Connector(
			connectionFactory: $connectionFactory,
			httpClient: $httpClient
		);
		$client = new Highway\Connector($wsClient);
		while (true) {
			$this->logger->info('[{uri}] Connecting', [
				'uri' => self::URI,
			]);
			try {
				$connection = $client->connect($handshake);
				$this->logger->info('[{uri}] Connected to websocket', [
					'uri' => self::URI,
				]);
				if ($connection->isSupportedVersion()) {
					return $connection;
				}
				$this->logger->error('[{uri}] runs unsupported highway-version {version}', [
					'uri' => self::URI,
					'version' => $connection->getVersion(),
				]);
				$connection->close(WebsocketCloseCode::NORMAL_CLOSE, 'Unsupported version');
				return null;
			} catch (Throwable $e) {
				if ($this->chatBot->isShuttingDown()) {
					return null;
				}
				if ($e instanceof WebsocketConnectException && $e->getResponse()->getStatus() === 404) {
					$this->logger->info('[{uri}] Service not up yet, reconnecting in {delay}s', [
						'uri' => self::URI,
						'delay' => self::RECONNECT_DELAY,
						'exception' => $e,
					]);
					delay(self::RECONNECT_DELAY);
					continue;
				}
				$this->logger->warning('[{uri}] {error} - reconnecting in {delay}s', [
					'uri' => self::URI,
					'error' => $e->getMessage(),
					'delay' => self::RECONNECT_DELAY,
					'exception' => $e,
				]);

				delay(self::RECONNECT_DELAY);
			}
		}
	}

	/**
	 * Connect to the Highway server and read/process all messages.
	 * Return if there are no more packages, or the server disconnects.
	 *
	 * @return bool `true` if the bot can reconnect, `false` otherwise
	 */
	private function singleLoop(): bool {
		try {
			$this->connection = $this->connect();
			if (!isset($this->connection)) {
				return false;
			}
			$this->announceConnect();
			$this->isReconnect = true;
			// @phpstan-ignore-next-line
			while ($package = $this->connection->receive()) {
				$this->handlePackage($this->connection, $package);
			}
		} catch (Throwable $e) {
			if ($this->chatBot->isShuttingDown()) {
				return false;
			}
			$error = $e->getMessage();
			if ($e instanceof WebsocketClosedException) {
				$error = 'Server closed the connection';
			} elseif ($e instanceof JsonException && isset($this->connection)) {
				$error = "JSON {$error}";
				$this->connection->close(WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE);
			} elseif (isset($this->connection)) {
				$this->connection->close();
			}
			$this->connection = null;
			$this->availableRooms = [];
			$this->logger->warning('[{uri}] {error} - reconnecting in {delay}s', [
				'uri' => self::URI,
				'delay' => self::RECONNECT_DELAY,
				'error' => $error,
				'exception' => $e,
			]);
			delay(self::RECONNECT_DELAY);
		}
		return true;
	}

	/** Dispatch a connect/reconnect event */
	private function announceConnect(): void {
		$event = $this->isReconnect ? new EventFeedReconnect() : new EventFeedConnect();
		try {
			$this->eventManager->dispatch($event);
		} catch (Throwable) {
			// ignore
		}
	}

	/** Send the right `EventFeedPackageEvent` for the given package */
	private function handlePackage(Highway\Connection $connection, Highway\In\InPackage $package): void {
		$event = match ($package::class) {
			Highway\In\Hello::class => new HelloPackageEvent(connection: $connection, package: $package),
			Highway\In\Join::class => new JoinPackageEvent(connection: $connection, package: $package),
			Highway\In\Leave::class => new LeavePackageEvent(connection: $connection, package: $package),
			Highway\In\Message::class => new MessagePackageEvent(connection: $connection, package: $package),
			Highway\In\Success::class => new SuccessPackageEvent(connection: $connection, package: $package),
			Highway\In\Error::class => new ErrorPackageEvent(connection: $connection, package: $package),
			Highway\In\RoomInfo::class => new RoomInfoPackageEvent(connection: $connection, package: $package),
			Highway\In\Result::class => new ResultPackageEvent(connection: $connection, package: $package),
			default => null,
		};
		if (!isset($event)) {
			$this->logger->error('Unknown event-feed type {event}', [
				'event' => $package->type,
			]);
			return;
		}
		try {
			$this->eventManager->dispatch($event);
		} catch (Throwable $e) {
			$this->logger->error('Error handling {event}: {error}', [
				'event' => EventManager::getEventType($event),
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
		}
	}

	/** Call the handlers for the Highway room the given message originated from. */
	private function handleMessage(MessagePackageEvent $event): void {
		$package = $event->getPackage();
		$this->logger->info('Message from global event feed for room {room}: {message}', [
			'room' => $package->room,
			'message' => $package,
		]);
		$body = $package->body;
		if (is_string($body)) {
			$body = json_decode($body, true);
		}

		/** @var list<EventFeedHandler> */
		$handlers = $this->roomHandlers[$package->room] ?? [];
		foreach ($handlers as $handler) {
			try {
				$handler->handleEventFeedMessage(
					$package->room,
					$body,
				);
			} catch (Throwable $e) {
				$this->logger->error('Error handling global event in {room}: {error}', [
					'room' => $package->room,
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
	}

	/** Handle Highway error events */
	private function handleError(ErrorPackageEvent $event): void {
		$package = $event->getPackage();
		if (isset($package->room)) {
			unset($this->attachedRooms[$package->room]);
			$this->logger->error('Error from global event feed. Unable to join {room}: {error}', [
				'room' => $package->room,
				'error' => $package->message,
			]);
		}
		$this->logger->error('Error from global event feed: {error}', [
			'error' => $package->message,
		]);
	}

	/** Handle Highway success events */
	private function handleSuccess(SuccessPackageEvent $event): void {
		$package = $event->getPackage();
		if (isset($package->room)) {
			$this->attachedRooms[$package->room] = true;
			$this->logger->info('Successfully joined room {room}', [
				'room' => $package->room,
			]);
		}
	}

	/** Handle Highway room-info events. Register them. */
	private function handleRoomInfo(RoomInfoPackageEvent $event): void {
		$this->attachedRooms[$event->getPackage()->room] = true;
	}

	/** Handle Highway hello events. Join all rooms for which we have handlers. */
	private function handleHello(HelloPackageEvent $event): void {
		$package = $event->getPackage();
		$attachedRooms = [];
		$this->availableRooms = [];
		$this->logger->notice('Public rooms on highway {version} server {server}: {rooms}', [
			'version' => $event->getConnection()->getVersion(),
			'server' => self::URI,
			'rooms' => $package->publicRooms,
		]);
		foreach ($package->publicRooms as $room) {
			$this->availableRooms[$room] = true;
			if (!isset($this->roomHandlers[$room]) || !count($this->roomHandlers[$room])) {
				continue;
			}
			$joinPackage = new Highway\Out\Join(room: $room);
			$event->getConnection()->send($joinPackage);
			$attachedRooms []= $room;
		}
		$this->logger->notice('Global event feed attached to {rooms}', [
			'rooms' => count($attachedRooms) ? Text::enumerate(...$attachedRooms) : 'no rooms',
		]);
	}
}
