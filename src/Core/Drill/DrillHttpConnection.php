<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use function Amp\delay;
use function Amp\Socket\connect;

use Amp\Socket\{ConnectContext, ConnectException, Socket};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

class DrillHttpConnection {
	private ?Socket $webClient = null;

	public function __construct(
		private readonly string $uuid,
		private readonly string $host,
		private readonly int $port,
		private DrillConnection $drillConnection,
		private LoggerInterface $logger,
	) {
	}

	public function loop(): bool {
		// Connect locally to the webserver
		$connectContext = new ConnectContext();

		$this->logger->info('Connecting Drill to {host}:{port}', [
			'host' => $this->host,
			'port' => $this->port,
		]);
		try {
			$this->webClient = connect($this->host . ':' . $this->port, $connectContext);
		} catch (ConnectException) {
			return false;
		}
		$this->logger->info('Connected Drill to local webserver');
		EventLoop::queue($this->mainLoop(...));
		return true;
	}

	public function handleDisconnect(): void {
		if (isset($this->webClient)) {
			$this->webClient->close();
		}
	}

	public function handle(Packet\Data $packet): void {
		$this->logger->info('Received package to route to webserver');
		while (!isset($this->webClient)) {
			$this->logger->info('Waiting for connection');
			delay(0.1);
		}
		$this->logger->info('Sending data to Webserver');
		$this->webClient->write($packet->data);
	}

	private function mainLoop(): void {
		while (isset($this->webClient) && ($chunk = $this->webClient->read()) !== null) {
			$this->logger->info('Received reply from Webserver');
			$packet = new Packet\Data(data: $chunk, uuid: $this->uuid);
			$this->drillConnection->send($packet);
		}
		$this->logger->info('Empty read from webserver, closing');
		if (isset($this->webClient)) {
			$packet = new Packet\Closed(uuid: $this->uuid);
			$this->drillConnection->send($packet);
		}
	}
}
