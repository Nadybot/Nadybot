<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use function Amp\delay;

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
	FunctionParameter,
	Nadybot,
	Types\LogWrapInterface,
};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
	RelayStatus,
	RelayStatusType,
	StatusProvider,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

/**
 * You can use websockets as a relay transport.
 * Websockets provide near-realtime communication, but since they
 * are not part of Anarchy Online, if they are down, you might have
 * a hard time debugging this.
 * Websockets require a transport protocol in order to work properly
 * and if they are public, you might also want to add an encryption
 * layer on top of that.
 */
#[NCA\RelayTransport(name: 'websocket')]
class Websocket implements TransportInterface, StatusProvider, LogWrapInterface {
	protected Relay $relay;

	protected ?RelayStatus $status = null;

	/** @var ?callable */
	protected mixed $initCallback;

	protected ?WebsocketConnection $client = null;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private HttpClientBuilder $clientBuilder;

	private bool $deinitializing = false;

	private ?string $retryHandler = null;

	/**
	 * @param string      $uri           The URI of the websocket to connect to
	 * @param null|string $authorization If set, authorize against the Websocket server with a password
	 */
	public function __construct(
		#[NCA\Param(name: 'server')] protected string $uri,
		#[NCA\Param(type: FunctionParameter::TYPE_SECRET)] protected ?string $authorization=null,
	) {
		$urlParts = Uri::new($uri);
		$scheme = $urlParts->getScheme();
		if ($scheme === null || $urlParts->getHost() === null) {
			throw new Exception("Invalid URI <highlight>{$uri}<end>.");
		}
		if (!in_array($scheme, ['ws', 'wss'], true)) {
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
		$message = '[Websocket {uri}] ' . $message;
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
		EventLoop::queue(function () use ($data): void {
			foreach ($data as $chunk) {
				$this->client?->sendText($chunk);
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
			$httpClientBuilder->intercept(new AddRequestHeader('Authorization', $this->authorization));
		}
		$httpClient = $httpClientBuilder->build();
		$client = new Rfc6455Connector(httpClient: $httpClient);
		EventLoop::queue(function () use ($callback, $client, $handshake): void {
			$reconnect = false;
			do {
				if ($this->deinitializing) {
					return;
				}
				$this->status = new RelayStatus(RelayStatusType::INIT, "Connecting to {$this->uri}");
				try {
					$connection = $client->connect($handshake, null);
				} catch (Throwable $e) {
					if ($this->chatBot->isShuttingDown()) {
						return;
					}
					$error = $e->getMessage();
					$this->logger->error('{error} - retrying in {delay}s', [
						'error' => $error,
						'delay' => 10,
						'exception' => $e,
					]);
					$this->status = new RelayStatus(RelayStatusType::INIT, $error);

					if ($e instanceof TimeoutException) {
						if (isset($this->initCallback)) {
							delay(10);
							$reconnect = true;
						} else {
							$this->client = null;
							$this->retryHandler = EventLoop::delay(10, function (string $token): void {
								$this->relay->init();
							});
							return;
						}
					} else {
						$this->client = null;
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
			$this->logger->notice('Connected successfully.');
			if (!isset($this->initCallback)) {
				return;
			}
			$callback = $this->initCallback;
			$this->initCallback = null;
			$this->status = new RelayStatus(RelayStatusType::READY, 'ready');
			$callback();
			EventLoop::queue($this->mainLoop(...));
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
		EventLoop::queue(function () use ($callback): void {
			try {
				$this->client?->close();
			} catch (Throwable) {
			}
			$callback();
		});
		return [];
	}

	private function mainLoop(): void {
		try {
			while (null !== ($message = $this->client?->receive())) {
				$data = $message->buffer();

				$msg = new RelayMessage(packages: [$data]);
				$this->relay->receiveFromTransport($msg);
			}
		} catch (Throwable $e) {
			if ($this->chatBot->isShuttingDown()) {
				return;
			}
			$this->logger->error('{error}, retrying in {delay}s', [
				'error' => $e->getMessage(),
				'delay' => 10,
				'exception' => $e,
			]);
			$this->status = new RelayStatus(RelayStatusType::INIT, $e->getMessage());
			$this->client = null;
			$this->retryHandler = EventLoop::delay(10, function (string $token): void {
				$this->relay->init();
			});
			return;
		}
		try {
			$this->client?->close();
		} catch (Throwable) {
		}
		$this->client = null;
		$this->logger->notice('Reconnecting.');
		$this->retryHandler = EventLoop::defer(function (string $token): void {
			$this->relay->init();
		});
	}
}
