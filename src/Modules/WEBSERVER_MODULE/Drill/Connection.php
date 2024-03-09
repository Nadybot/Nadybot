<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Drill;

use function Amp\Socket\connect;
use function Amp\{async, delay};

use Amp\Socket\{ConnectContext, ConnectException, Socket};
use Amp\Websocket\Client\WebsocketConnection;
use Nadybot\Core\{Attributes as NCA, LoggerWrapper, Registry};
use Nadybot\Modules\WEBSERVER_MODULE\WebserverController;

class Connection {
	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	private WebserverController $wsCtrl;

	private ?Socket $webClient;

	public function __construct(
		public string $uuid,
		public WebsocketConnection $wsConnection,
	) {
	}

	public function loop(): bool {
		// Connect locally to the webserver
		$host = '127.0.0.1';
		$port = $this->wsCtrl->webserverPort;

		$connectContext = new ConnectContext();

		$this->logger->info("Connecting Drill to {host}:{port}", [
			"host" => $host,
			"port" => $port,
		]);
		try {
			$this->webClient = connect($host . ':' . $port, $connectContext);
		} catch (ConnectException $e) {
			return false;
		}
		$this->logger->info("Connected Drill to local webserver");
		async($this->mainLoop(...));
		return true;
	}

	public function handleDisconnect(): void {
		if (isset($this->webClient)) {
			$this->webClient->close();
		}
	}

	public function handle(Packet\Data $packet): void {
		$this->logger->info("Received package to route to webserver");
		while (!isset($this->webClient)) {
			$this->logger->info("Waiting for connection");
			delay(0.1);
		}
		$this->logger->info("Sending data to Webserver");
		$this->webClient->write($packet->data);
	}

	private function mainLoop(): void {
		while (isset($this->webClient) && ($chunk = $this->webClient->read()) !== null) {
			$this->logger->info("Received reply from Webserver");
			$packet = new Packet\Data(data: $chunk, uuid: $this->uuid);
			Registry::injectDependencies($packet);
			$this->logger->debug("Sending answer to Drill server: {answer}", [
				"answer" => $chunk,
			]);
			$packet->send($this->wsConnection);
		}
		$this->logger->info("Empty read from webserver, closing");
		if (isset($this->webClient)) {
			$packet = new Packet\Closed(uuid: $this->uuid);
			Registry::injectDependencies($packet);
			$packet->send($this->wsConnection);
		}
	}
}
