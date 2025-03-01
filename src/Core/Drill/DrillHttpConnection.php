<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use function Amp\delay;
use function Amp\Socket\connect;

use Amp\Socket\{ConnectContext, ConnectException, Socket};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/** This represents a HTTP-connection from the drill server to us */
class DrillHttpConnection {
	private ?Socket $webClient = null;

	/**
	 * @param string          $uuid            The UUID of this connection
	 * @param string          $host            The host to link this connection to
	 * @param int             $port            The port to link this connection to
	 * @param DrillConnection $drillConnection The active drill connection
	 * @param LoggerInterface $logger          The logger to use
	 */
	public function __construct(
		private readonly string $uuid,
		private readonly string $host,
		private readonly int $port,
		private DrillConnection $drillConnection,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Start the loop to connect the remote HTTP-connection on the drill server
	 * to our bot's HTTP server
	 */
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

	/** Handle a client disconnecting */
	public function handleDisconnect(): void {
		if (isset($this->webClient)) {
			$this->webClient->close();
		}
	}

	/** Forward HTTP packages received from drill to our bot's HTTP server */
	public function handle(Packet\Data $packet): void {
		$this->logger->info('Received package to route to webserver');
		while (!isset($this->webClient)) {
			$this->logger->info('Waiting for connection');
			delay(0.1);
		}
		$this->logger->info('Sending data to Webserver');
		$this->webClient->write($packet->data);
	}

	/** Forward all package replies from our webserver to the drill connection */
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
