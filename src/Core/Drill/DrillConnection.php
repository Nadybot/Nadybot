<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use Amp\Cancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketClosedException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

class DrillConnection {
	public function __construct(
		private WebsocketConnection $connection,
		private UriInterface $uri,
		private LoggerInterface $logger,
	) {
	}

	public function close(): void {
		$this->connection->close();
	}

	public function receive(?Cancellation $cancellation=null): ?AbstractDrillPacket {
		if (null !== ($message = $this->connection->receive($cancellation))) {
			$payload = $message->buffer($cancellation);

			return $this->parseDrillMessage($payload);
		}
		if ($this->connection->getCloseInfo()->isByPeer()) {
			throw new WebsocketClosedException(
				'Drill unexpectedly closed the connection',
				$this->connection->getCloseInfo()->getCode(),
				$this->connection->getCloseInfo()->getReason(),
			);
		}
		return null;
	}

	public function send(AbstractDrillPacket $packet): void {
		$this->logger->debug('Sending Drill packet to {url}: {packet}', [
			'url' => $this->uri,
			'packet' => $packet,
		]);
		$this->connection->sendBinary($packet->toString());
	}

	private function parseDrillMessage(string $payload): AbstractDrillPacket {
		try {
			$packet = PacketFactory::parse($payload);
		} catch (UnsupportedPacketException $e) {
			$this->logger->warning('Received unsupported Drill package type {type}', [
				'type' => $e->getMessage(),
				'exception' => $e,
			]);
			throw $e;
		}
		$this->logger->debug('Received Drill packet from {url}: {package}', [
			'url' => $this->uri,
			'package' => $packet,
		]);
		return $packet;
	}
}
