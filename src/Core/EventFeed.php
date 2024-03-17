<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\{async, delay};
use function Safe\json_decode;
use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Socket\ConnectContext;
use Amp\Websocket\Client\{Rfc6455ConnectionFactory, Rfc6455Connector, WebsocketConnectException, WebsocketHandshake};
use Amp\Websocket\{PeriodicHeartbeatQueue, WebsocketCloseCode, WebsocketClosedException};
use AssertionError;
use Closure;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Event\{EventFeedConnect, EventFeedReconnect};
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Safe\Exceptions\JsonException;
use Throwable;

/**
 * This class is the interface to the public highway channels
 *
 * @author Nadyita
 */
#[
	NCA\Instance,
	NCA\ProvidesEvent("event-feed(*)"),
	NCA\ProvidesEvent("event-feed-connect"),
	NCA\ProvidesEvent("event-feed-reconnect"),
]
class EventFeed {
	public const URI = "wss://ws.nadybot.org";
	public const RECONNECT_DELAY = 5;

	/** @var array<string,EventFeedHandler[]> */
	public array $roomHandlers = [];

	public ?Highway\Connection $connection=null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $clientBuilder;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private EventManager $eventManager;

	private bool $isReconnect = false;

	/** @var array<string,bool> */
	private array $attachedRooms = [];

	/** @var array<string,bool> */
	private array $availableRooms = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->eventManager->subscribe("event-feed(hello)", Closure::fromCallable([$this, "handleHello"]));
		$this->eventManager->subscribe("event-feed(error)", Closure::fromCallable([$this, "handleError"]));
		$this->eventManager->subscribe("event-feed(success)", Closure::fromCallable([$this, "handleSuccess"]));
		$this->eventManager->subscribe("event-feed(room-info)", Closure::fromCallable([$this, "handleRoomInfo"]));
		$this->eventManager->subscribe("event-feed(message)", Closure::fromCallable([$this, "handleMessage"]));

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

	public function registerEventFeedHandler(string $room, EventFeedHandler $handler): void {
		$this->roomHandlers[$room] ??= [];
		$this->roomHandlers[$room] []= $handler;
		$this->logger->info("New event feed handler for {room}: {handler}", [
				"room" => $room,
				"handler" => $handler,
		]);
		if (!isset($this->connection)) {
			$this->logger->info("Not connected to {server} - not joining \"{room}\" now", [
				"server" => self::URI,
				"room" => $room,
			]);
			return;
		}
		if (!isset($this->availableRooms[$room])) {
			$this->logger->notice("Room \"{room}\" not found on {server}. Available: {rooms}", [
				"server" => self::URI,
				"room" => $room,
				"rooms" => array_keys($this->availableRooms),
			]);
			return;
		}
		async(function () use ($room): void {
			$joinPackage = new Highway\Out\Join(room: $room);
			$announcer = function (LowLevelEventFeedEvent $event) use ($room, &$announcer): void {
				assert($event->highwayPackage instanceof Highway\In\RoomInfo);
				if ($event->highwayPackage->room === $room) {
					$this->logger->notice("Global event feed attached to {room}", [
						"room" => $room,
					]);
					$this->eventManager->unsubscribe("event-feed(room-info)", $announcer);
				}
			};
			$this->eventManager->subscribe("event-feed(room-info)", $announcer);
			if (isset($this->connection)) {
				$this->connection->send($joinPackage);
			}
		});
	}

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
		$this->logger->info("Removed event feed handler for \"{room}\": {handler}", [
				"room" => $room,
				"handler" => $handler,
		]);
		if (!isset($this->connection) || count($this->roomHandlers[$room])) {
			return;
		}
		if (!isset($this->availableRooms[$room])) {
			$this->logger->notice("Room \"{room}\" not found on {server}. Available: {rooms}", [
				"server" => self::URI,
				"room" => $room,
				"rooms" => array_keys($this->availableRooms),
			]);
			return;
		}
		async(function () use ($room): void {
			$leavePackage = new Highway\Out\Leave(room: $room);
			$announcer = function (LowLevelEventFeedEvent $event) use ($room, &$announcer): void {
				assert($event->highwayPackage instanceof Highway\In\Success);
				$this->logger->notice("Global event feed detached from {room}", [
					"room" => $room,
				]);
				$this->eventManager->unsubscribe("event-feed(success)", $announcer);
				unset($this->attachedRooms[$room]);
			};
			$this->eventManager->subscribe("event-feed(success)", $announcer);
			if (isset($this->connection)) {
				$this->connection->send($leavePackage);
			}
		});
	}

	public function mainLoop(): void {
		async(function (): void {
			while ($this->singleLoop()) {
				delay(self::RECONNECT_DELAY);
			}
		});
	}

	protected function connect(): ?Highway\Connection {
		$connectionFactory = new Rfc6455ConnectionFactory(
			heartbeatQueue: new PeriodicHeartbeatQueue(
				heartbeatPeriod: 5, // 5 seconds
			),
		);

		$connector = new Rfc6455Connector($connectionFactory);

		$handshake = (new WebsocketHandshake(self::URI))
			->withTcpConnectTimeout(3000)
			->withTlsHandshakeTimeout(3000);
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
			$this->logger->info("[{uri}] Connecting", [
				"uri" => self::URI,
			]);
			try {
				$connection = $client->connect($handshake);
				if (!isset($connection)) {
					return null;
				}
				$this->logger->info("[{uri}] Connected to websocket", [
					"uri" => self::URI,
				]);
				if ($connection->isSupportedVersion()) {
					return $connection;
				}
				$this->logger->error("[{uri}] runs unsupported highway-version {version}", [
					"uri" => self::URI,
					"version" => $connection->getVersion(),
				]);
				$connection->close(WebsocketCloseCode::NORMAL_CLOSE, "Unsupported version");
				return null;
			} catch (Throwable $e) {
				if ($this->chatBot->isShuttingDown()) {
					return null;
				}
				if ($e instanceof WebsocketConnectException && $e->getResponse()->getStatus() === 404) {
					$this->logger->info("[{uri}] Service not up yet, reconnecting in {delay}s", [
						"uri" => self::URI,
						"delay" => self::RECONNECT_DELAY,
					]);
					delay(self::RECONNECT_DELAY);
					continue;
				}
				$this->logger->warning("[{uri}] {error} - reconnecting in {delay}s", [
					"uri" => self::URI,
					"error" => $e->getMessage(),
					"delay" => self::RECONNECT_DELAY,
					"exception" => $e,
				]);

				delay(self::RECONNECT_DELAY);
			}
		}
	}

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
				$error = "Server closed the connection";
			} elseif ($e instanceof JsonException && isset($this->connection)) {
				$error = "JSON {$error}";
				$this->connection->close(WebsocketCloseCode::INCONSISTENT_FRAME_DATA_TYPE);
			} elseif (isset($this->connection)) {
				$this->connection->close();
			}
			$this->connection = null;
			$this->availableRooms = [];
			$this->logger->warning("[{uri}] {error} - reconnecting in {delay}s", [
				"uri" => self::URI,
				"delay" => self::RECONNECT_DELAY,
				"error" => $error,
				"exception" => $e,
			]);
			delay(self::RECONNECT_DELAY);
		}
		return true;
	}

	private function announceConnect(): void {
		$event = $this->isReconnect ? new EventFeedReconnect() : new EventFeedConnect();
		try {
			$this->eventManager->fireEvent($event);
		} catch (Throwable) {
			// ignore
		}
	}

	private function handlePackage(Highway\Connection $connection, Highway\In\InPackage $package): void {
		$event = new LowLevelEventFeedEvent(
			type: "event-feed({$package->type})",
			connection: $connection,
			highwayPackage: $package
		);
		try {
			$this->eventManager->fireEvent($event);
		} catch (AssertionError $e) {
			$this->logger->error(
				"Unexpected protocol inconsistency for {event}: {error} in {file}#{line}",
				[
					"event" => $event->type,
					"error" => $e->getMessage(),
					"file" => $e->getFile(),
					"line" => $e->getLine(),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error("Error handling {event}: {error}", [
				"event" => $event->type,
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
		}
	}

	private function handleMessage(LowLevelEventFeedEvent $event): void {
		assert($event->highwayPackage instanceof Highway\In\Message);
		$this->logger->info("Message from global event feed for room {room}: {message}", [
			"room" => $event->highwayPackage->room,
			"message" => $event->highwayPackage->body,
		]);
		$body = $event->highwayPackage->body;
		if (is_string($body)) {
			$body = json_decode($body, true);
		}

		/** @var EventFeedHandler[] */
		$handlers = $this->roomHandlers[$event->highwayPackage->room] ?? [];
		foreach ($handlers as $handler) {
			try {
				$handler->handleEventFeedMessage(
					$event->highwayPackage->room,
					$body,
				);
			} catch (Throwable $e) {
				$this->logger->error("Error handling global event in {room}: {error}", [
					"room" => $event->highwayPackage->room,
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
			}
		}
	}

	private function handleError(LowLevelEventFeedEvent $event): void {
		if (!($event->highwayPackage instanceof Highway\In\Error)) {
			return;
		}
		if (isset($event->highwayPackage->room)) {
			unset($this->attachedRooms[$event->highwayPackage->room]);
			$this->logger->error("Error from global event feed. Unable to join {room}: {error}", [
				"room" => $event->highwayPackage->room,
				"error" => $event->highwayPackage->message,
			]);
		}
		$this->logger->error("Error from global event feed: {error}", [
			"error" => $event->highwayPackage->message,
		]);
	}

	private function handleSuccess(LowLevelEventFeedEvent $event): void {
		assert($event->highwayPackage instanceof Highway\In\Success);
		if (isset($event->highwayPackage->room)) {
			$this->attachedRooms[$event->highwayPackage->room] = true;
			$this->logger->info("Successfully joined room {room}", [
				"room" => $event->highwayPackage->room,
			]);
		}
	}

	private function handleRoomInfo(LowLevelEventFeedEvent $event): void {
		assert($event->highwayPackage instanceof Highway\In\RoomInfo);
		$this->attachedRooms[$event->highwayPackage->room] = true;
	}

	private function handleHello(LowLevelEventFeedEvent $event): void {
		assert($event->highwayPackage instanceof Highway\In\Hello);
		$attachedRooms = [];
		$this->availableRooms = [];
		$this->logger->notice("Public rooms on highway {version} server {server}: {rooms}", [
			"version" => $event->connection->getVersion(),
			"server" => self::URI,
			"rooms" => $event->highwayPackage->publicRooms,
		]);
		foreach ($event->highwayPackage->publicRooms as $room) {
			$this->availableRooms[$room] = true;
			if (!isset($this->roomHandlers[$room]) || !count($this->roomHandlers[$room])) {
				continue;
			}
			$joinPackage = new Highway\Out\Join(room: $room);
			$event->connection->send($joinPackage);
			$attachedRooms []= $room;
		}
		$this->logger->notice("Global event feed attached to {rooms}", [
			"rooms" => count($attachedRooms) ? $this->text->enumerate(...$attachedRooms) : "no rooms",
		]);
	}
}
