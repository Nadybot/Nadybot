<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill;

use function Amp\Socket\connect;
use function Amp\{asyncCall, call, delay};

use Amp\Socket\{ConnectContext, ConnectException, EncryptableSocket};
use Amp\Websocket\Client\Connection as WebsocketConnection;
use Amp\{Promise, Success};
use Generator;
use Nadybot\Core\{Attributes as NCA, LoggerWrapper};
use Nadybot\Modules\WEBSERVER_MODULE\WebserverController;

class Connection {
	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public WebserverController $wsCtrl;

	private ?EncryptableSocket $webClient;

	public function __construct(
		public string $uuid,
		public WebsocketConnection $wsConnection,
	) {
	}

	/** @return Promise<bool> */
	public function loop(): Promise {
		return call(function (): Generator {
			// Connect locally to the webserver
			$host = '127.0.0.1';
			$port = $this->wsCtrl->webserverPort;

			$connectContext = new ConnectContext();

			$this->logger->info("Connecting Drill to {$host}:{$port}");
			try {
				$this->webClient = yield connect($host . ':' . $port, $connectContext);
			} catch (ConnectException $e) {
				return new Success(false);
			}
			$this->logger->info("Connected Drill to local webserver");
			asyncCall(function (): Generator {
				while (isset($this->webClient) && ($chunk = yield $this->webClient->read()) !== null) {
					$this->logger->info("Received reply from Webserver");
					$packet = new Packet\Data(data: $chunk, uuid: $this->uuid);
					$this->logger->debug("Sending answer to Drill server: {answer}", [
						"answer" => $chunk,
					]);
					yield $packet->send($this->wsConnection);
				}
				$this->logger->info("Empty read from webserver, closing");
				if (isset($this->webClient)) {
					$packet = new Packet\Closed(uuid: $this->uuid);
					yield $packet->send($this->wsConnection);
				}
			});
			return new Success(true);
		});
	}

	public function handleDisconnect(): void {
		if (isset($this->webClient)) {
			$this->webClient->close();
		}
	}

	/** @return Promise<void> */
	public function handle(Packet\Data $packet): Promise {
		return call(function () use ($packet): Generator {
			$this->logger->info("Received package to route to webserver");
			while (!isset($this->webClient)) {
				$this->logger->info("Waiting for connection");
				yield delay(100);
			}
			$this->logger->info("Sending data to Webserver");
			yield $this->webClient->write($packet->data);
		});
	}
}
