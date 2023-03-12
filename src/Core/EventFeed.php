<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\{asyncCall, call, delay};
use function Safe\{json_decode};
use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool, UnprocessedRequestException};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Promise;
use Amp\Socket\ConnectContext;
use Amp\Websocket\Client\{Handshake, Rfc6455Connector};
use Amp\Websocket\ClosedException;
use AssertionError;
use Closure;
use Generator;
use Nadybot\Core\Attributes as NCA;
use ReflectionClass;
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

	#[NCA\Inject]
	public HttpClientBuilder $clientBuilder;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<string,EventFeedHandler[]> */
	public array $roomHandlers = [];

	private bool $isReconnect = false;

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
					$this->roomHandlers[$attribute->room] ??= [];
					$this->roomHandlers[$attribute->room] []= $instance;
					$this->logger->info(
						"New event feed handler for {room}: {handler}",
						[
							"room" => $attribute->room,
							"handler" => $instance,
						]
					);
				}
			}
		}
	}

	public function mainLoop(): void {
		asyncCall(function (): Generator {
			while (yield $this->singleLoop()) {
				yield delay(self::RECONNECT_DELAY * 1000);
			}
		});
	}

	/** @return Promise<?Highway\Connection> */
	protected function connect(): Promise {
		return call(function (): Generator {
			$handshake = (new Handshake(self::URI))
				->withTcpConnectTimeout(3000)
				->withTlsHandshakeTimeout(3000);
			$connectContext = (new ConnectContext())->withTcpNoDelay();
			$httpClientBuilder = $this->clientBuilder
				->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
				->intercept(new RemoveRequestHeader('origin'));
			$httpClient = $httpClientBuilder->build();
			$wsClient = new Rfc6455Connector($httpClient);
			$client = new Highway\Connector($wsClient);
			while (true) {
				$this->logger->info("[{uri}] Connecting", [
					"uri" => self::URI,
				]);
				try {
					/** @var null|Highway\Connection */
					$connection = yield $client->connect($handshake);
					if (!isset($connection)) {
						return null;
					}
					$this->logger->info("[{uri}] Connected to websocket", [
						"uri" => self::URI,
					]);
					return $connection;
				} catch (Throwable $e) {
					if ($this->chatBot->isShuttingDown()) {
						return null;
					}
					if ($e instanceof UnprocessedRequestException) {
						$prev = $e->getPrevious();
						if (isset($prev)) {
							$e = $prev;
						}
					}
					$this->logger->error("[{uri}] {error} - retrying in {delay}s", [
						"uri" => self::URI,
						"error" => $e->getMessage(),
						"delay" => self::RECONNECT_DELAY,
						"exception" => $e,
					]);

					yield delay(self::RECONNECT_DELAY * 1000);
				}
			}
		});
	}

	/** @return Promise<bool> */
	private function singleLoop(): Promise {
		return call(function (): Generator {
			try {
				/** @var ?Highway\Connection */
				$connection = yield $this->connect();
				if (!isset($connection)) {
					return false;
				}
				$this->announceConnect();
				$this->isReconnect = true;
				while ($package = yield $connection->receive()) {
					$this->handlePackage($connection, $package);
				}
			} catch (Throwable $e) {
				if ($this->chatBot->isShuttingDown()) {
					return false;
				}
				$error = $e->getMessage();
				if ($e instanceof ClosedException) {
					$error = "Server unexpectedly closed the connection";
				}
				$this->logger->error("[{uri}] {error} - retrying in {delay}s", [
					"uri" => self::URI,
					"delay" => self::RECONNECT_DELAY,
					"error" => $error,
					"exception" => $e,
				]);
				yield delay(self::RECONNECT_DELAY * 1000);
			}
			return true;
		});
	}

	private function announceConnect(): void {
		$event = new Event();
		$event->type = "event-feed-" . ($this->isReconnect ? "reconnect" : "connect");
		try {
			$this->eventManager->fireEvent($event);
		} catch (Throwable) {
			// ignore
		}
	}

	private function handlePackage(Highway\Connection $connection, Highway\Package $package): void {
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

	private function handleMessage(LowLevelEventFeedEvent $event): Generator {
		assert($event->highwayPackage instanceof Highway\Message);
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
				yield $handler->handleEventFeedMessage(
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
		if (!($event->highwayPackage instanceof Highway\Error)) {
			return;
		}
		$this->logger->error("Error from global event feed: {error}", [
			"error" => $event->highwayPackage->message,
		]);
	}

	private function handleSuccess(LowLevelEventFeedEvent $event): void {
		assert($event->highwayPackage instanceof Highway\Success);
	}

	private function handleRoomInfo(LowLevelEventFeedEvent $event): void {
		assert($event->highwayPackage instanceof Highway\RoomInfo);
	}

	private function handleHello(LowLevelEventFeedEvent $event): Generator {
		assert($event->highwayPackage instanceof Highway\Hello);
		$attachedRooms = [];
		foreach ($event->highwayPackage->publicRooms as $room) {
			if (!isset($this->roomHandlers[$room])) {
				continue;
			}
			$joinPackage = new Highway\Join($room);
			yield $event->connection->send($joinPackage);
			$attachedRooms []= $room;
		}
		$this->logger->notice("Global event feed attached to {rooms}", [
			"rooms" => $this->text->enumerate(...$attachedRooms),
		]);
	}
}