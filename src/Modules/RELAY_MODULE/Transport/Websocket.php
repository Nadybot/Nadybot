<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use function Amp\{async, delay};

use Amp\Http\Client\{
	Connection\DefaultConnectionFactory,
	Connection\UnlimitedConnectionPool,
	HttpClientBuilder,
	Interceptor\AddRequestHeader,
	Interceptor\RemoveRequestHeader,
	TimeoutException,
};
use Amp\Websocket\{
	Client\Rfc6455Connector,
	Client\WebsocketConnection,
	Client\WebsocketHandshake,
};
use Amp\{
	Socket\ConnectContext,
};
use Exception;
use League\Uri\Uri;
use Nadybot\Core\{
	Attributes as NCA,
	LogWrapInterface,
	Nadybot,
};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
	RelayStatus,
	StatusProvider,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

#[
	NCA\RelayTransport(
		name: "websocket",
		description: "You can use websockets as a relay transport.\n".
			"Websockets provide near-realtime communication, but since they\n".
			"are not part of Anarchy Online, if they are down, you might have\n".
			"a hard time debugging this.\n".
			"Websockets require a transport protocol in order to work properly\n".
			"and if they are public, you might also want to add an encryption\n".
			"layer on top of that."
	),
	NCA\Param(
		name: "server",
		type: "string",
		description: "The URI of the websocket to connect to",
		required: true
	),
	NCA\Param(
		name: "authorization",
		type: "secret",
		description: "If set, authorize against the Websocket server with a password",
		required: false
	)
]
class Websocket implements TransportInterface, StatusProvider, LogWrapInterface {
	protected Relay $relay;

	protected ?RelayStatus $status = null;

	protected string $uri;
	protected ?string $authorization;

	/** @var ?callable */
	protected $initCallback;

	protected WebsocketConnection $client;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private HttpClientBuilder $clientBuilder;

	private bool $deinitializing = false;

	private ?string $retryHandler = null;

	public function __construct(string $uri, ?string $authorization=null) {
		$this->uri = $uri;
		$urlParts = Uri::new($uri);
		$scheme = $urlParts->getScheme();
		if ($scheme === null || $urlParts->getHost() === null) {
			throw new Exception("Invalid URI <highlight>{$uri}<end>.");
		}
		if (!in_array($scheme, ['ws', 'wss'])) {
			throw new Exception("<highlight>{$scheme}<end> is not a valid schema. Valid are ws and wss.");
		}
		$this->authorization = $authorization;
	}

	/**
	 * Wrap the logger by always adding the URI
	 *
	 * @param 100|200|250|300|400|500|550|600 $logLevel
	 * @param array<string, mixed>            $context
	 *
	 * @return array{100|200|250|300|400|500|550|600,string,array<string,mixed>}
	 */
	public function wrapLogs(int $logLevel, string $message, array $context): array {
		$message = "[Websocket {uri}] " . $message;
		$context['uri'] = $this->uri;
		return [$logLevel, $message, $context];
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function getStatus(): RelayStatus {
		return $this->status ?? new RelayStatus();
	}

	public function send(array $data): array {
		if (!isset($this->client)) {
			return [];
		}
		async(function () use ($data): void {
			foreach ($data as $chunk) {
				$this->client->sendText($chunk);
			}
		});
		return [];
	}

	public function init(callable $callback): array {
		$this->initCallback = $callback;
		$handshake = new WebsocketHandshake($this->uri);
		$connectContext = (new ConnectContext())->withTcpNoDelay();
		$httpClientBuilder = $this->clientBuilder
			->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
			->intercept(new RemoveRequestHeader('origin'));
		if (isset($this->authorization)) {
			$httpClientBuilder->intercept(new AddRequestHeader("Authorization", $this->authorization));
		}
		$httpClient = $httpClientBuilder->build();
		$client = new Rfc6455Connector(httpClient: $httpClient);
		async(function () use ($callback, $client, $handshake): void {
			$reconnect = false;
			do {
				if ($this->deinitializing) {
					return;
				}
				$this->status = new RelayStatus(RelayStatus::INIT, "Connecting to {$this->uri}");
				try {
					$connection = $client->connect($handshake, null);
				} catch (Throwable $e) {
					if ($this->chatBot->isShuttingDown()) {
						return;
					}
					$error = $e->getMessage();
					$this->logger->error("{error} - retrying in {delay}s", [
						"error" => $error,
						"delay" => 10,
						"exception" => $e,
					]);
					$this->status = new RelayStatus(RelayStatus::INIT, $error);

					if ($e instanceof TimeoutException) {
						if (isset($this->initCallback)) {
							delay(10);
							$reconnect = true;
						} else {
							unset($this->client);
							$this->retryHandler = EventLoop::delay(10, function (string $token): void {
								$this->relay->init();
							});
							return;
						}
					} else {
						unset($this->client);
						$this->retryHandler = EventLoop::delay(10, function (string $token): void {
							$this->relay->init();
						});
						return;
					}
				}
			} while ($reconnect);
			if (!isset($connection)) {
				return;
			}
			$this->client = $connection;
			$this->logger->notice("Connected successfully.");
			if (!isset($this->initCallback)) {
				return;
			}
			$callback = $this->initCallback;
			unset($this->initCallback);
			$this->status = new RelayStatus(RelayStatus::READY, "ready");
			$callback();
			async($this->mainLoop(...));
		});
		return [];
	}

	public function deinit(callable $callback): array {
		$this->deinitializing = true;
		if (isset($this->retryHandler)) {
			EventLoop::cancel($this->retryHandler);
			$this->retryHandler = null;
		}
		if (!isset($this->client) || $this->client->isClosed()) {
			$callback();
			return [];
		}
		async(function () use ($callback): void {
			try {
				$this->client->close();
			} catch (Throwable) {
			}
			$callback();
		});
		return [];
	}

	private function mainLoop(): void {
		try {
			while (null !== ($message = $this->client->receive())) {
				$data = $message->buffer();

				/** @var string $data */
				$msg = new RelayMessage();
				$msg->packages = [$data];
				$this->relay->receiveFromTransport($msg);
			}
		} catch (Throwable $e) {
			if ($this->chatBot->isShuttingDown()) {
				return;
			}
			$this->logger->error("{error}, retrying in {delay}s", [
				"error" => $e->getMessage(),
				"delay" => 10,
				"exception" => $e,
			]);
			$this->status = new RelayStatus(RelayStatus::INIT, $e->getMessage());
			unset($this->client);
			$this->retryHandler = EventLoop::delay(10, function (string $token): void {
				$this->relay->init();
			});
			return;
		}
		try {
			$this->client->close();
		} catch (Throwable) {
		}
		unset($this->client);
		$this->logger->notice("Reconnecting.");
		$this->retryHandler = EventLoop::defer(function (string $token): void {
			$this->relay->init();
		});
	}
}
