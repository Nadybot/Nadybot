<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use function Amp\{
	Promise\rethrow,
	asyncCall,
	call,
	delay,
};

use Amp\Http\Client\{
	Connection\DefaultConnectionFactory,
	Connection\UnlimitedConnectionPool,
	Connection\UnprocessedRequestException,
	HttpClientBuilder,
	Interceptor\AddRequestHeader,
	Interceptor\RemoveRequestHeader,
	Interceptor\SetRequestHeaderIfUnset,
	TimeoutException,
};
use Amp\Websocket\{
	Client\Connection,
	Client\Handshake,
	Client\Rfc6455Connector,
	Message,
};
use Amp\{
	Loop,
	Promise,
	Socket\ConnectContext,
};
use Exception;
use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	LoggerWrapper,
	Nadybot,
};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
	RelayStatus,
	StatusProvider,
};
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
class Websocket implements TransportInterface, StatusProvider {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	protected Relay $relay;

	protected ?RelayStatus $status = null;

	protected string $uri;
	protected ?string $authorization;

	/** @var ?callable */
	protected $initCallback;

	protected Connection $client;

	private bool $deinitializing = false;

	private ?string $retryHandler = null;

	public function __construct(string $uri, ?string $authorization=null) {
		$this->uri = $uri;
		$urlParts = parse_url($this->uri);
		if ($urlParts === false
			|| empty($urlParts)
			|| empty($urlParts['scheme'])
			|| empty($urlParts['host'])
		) {
			throw new Exception("Invalid URI <highlight>{$uri}<end>.");
		}
		if (!in_array($urlParts['scheme'], ['ws', 'wss'])) {
			throw new Exception("<highlight>{$urlParts['scheme']}<end> is not a valid schema. Valid are ws and wss.");
		}
		$this->authorization = $authorization;
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
		asyncCall(function () use ($data): Generator {
			foreach ($data as $chunk) {
				yield $this->client->send($chunk);
			}
		});
		return [];
	}

	public function init(callable $callback): array {
		$this->initCallback = $callback;
		$handshake = new Handshake($this->uri);
		$connectContext = (new ConnectContext())->withTcpNoDelay();
		$httpClientBuilder = (new HttpClientBuilder())
			->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
			->intercept(new RemoveRequestHeader('origin'))
			->intercept(new SetRequestHeaderIfUnset("User-Agent", "Nadybot ".BotRunner::getVersion()));
		if (isset($this->authorization)) {
			$httpClientBuilder->intercept(new AddRequestHeader("Authorization", $this->authorization));
		}
		$httpClientBuilder->retry(10);
		$httpClient = $httpClientBuilder->build();
		$client = new Rfc6455Connector($httpClient);
		asyncCall(function () use ($callback, $client, $handshake): Generator {
			$reconnect = false;
			do {
				if ($this->deinitializing) {
					return;
				}
				$this->status = new RelayStatus(RelayStatus::INIT, "Connecting to {$this->uri}");
				try {
					/** @var Connection */
					$connection = yield $client->connect($handshake, null);
				} catch (Throwable $e) {
					if ($e instanceof UnprocessedRequestException) {
						$prev = $e->getPrevious();
						if (isset($prev)) {
							$e = $prev;
						}
					}
					$error = $e->getMessage();
					$this->logger->error("[{$this->uri}] {$error} - retrying in 10s");
					$this->status = new RelayStatus(RelayStatus::INIT, $error);

					if ($e instanceof TimeoutException) {
						if (isset($this->initCallback)) {
							yield delay(10000);
							$reconnect = true;
						} else {
							unset($this->client);
							$this->retryHandler = Loop::delay(10000, fn () => $this->relay->init());
							return;
						}
					} else {
						unset($this->client);
						$this->retryHandler = Loop::delay(10000, fn () => $this->relay->init());
						return;
					}
				}
			} while ($reconnect);
			if (!isset($connection)) {
				return;
			}
			$this->client = $connection;
			$this->logger->notice("Connected to Websocket {$this->uri}.");
			if (!isset($this->initCallback)) {
				return;
			}
			$callback = $this->initCallback;
			unset($this->initCallback);
			$this->status = new RelayStatus(RelayStatus::READY, "ready");
			$callback();
			rethrow($this->mainLoop());
		});
		return [];
	}

	public function deinit(callable $callback): array {
		$this->deinitializing = true;
		if (isset($this->retryHandler)) {
			Loop::cancel($this->retryHandler);
			$this->retryHandler = null;
		}
		if (!isset($this->client) || !$this->client->isConnected()) {
			$callback();
			return [];
		}
		asyncCall(function () use ($callback): Generator {
			try {
				yield $this->client->close();
			} catch (Throwable) {
			}
			$callback();
		});
		return [];
	}

	/** @return Promise<void> */
	private function mainLoop(): Promise {
		return call(function (): Generator {
			try {
				while ($message = yield $this->client->receive()) {
					/** @var Message $message */
					$data = yield $message->buffer();

					/** @var string $data */
					$msg = new RelayMessage();
					$msg->packages = [$data];
					$this->relay->receiveFromTransport($msg);
				}
			} catch (Throwable $e) {
				$this->logger->error("[{uri}] {error}, retrying in 10s", [
					"uri" => $this->uri,
					"error" => $e->getMessage(),
					"exception" => $e,
				]);
				$this->status = new RelayStatus(RelayStatus::INIT, $e->getMessage());
				unset($this->client);
				$this->retryHandler = Loop::delay(10000, fn () => $this->relay->init());
				return;
			}
			try {
				yield $this->client->close();
			} catch (Throwable) {
			}
			unset($this->client);
			$this->logger->notice("Reconnecting to Websocket {$this->uri}.");
			$this->retryHandler = Loop::defer(fn () => $this->relay->init());
		});
	}
}
