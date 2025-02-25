<?php declare(strict_types=1);

namespace Nadybot\Core\Drill;

use Amp\Cancellation;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketClosedException;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

/** This is an active drill connection */
class DrillConnection {
	public function __construct(
		private WebsocketConnection $connection,
		private UriInterface $uri,
		private LoggerInterface $logger,
	) {
	}

	/** Close the connection */
	public function close(): void {
		$this->connection->close();
	}

	/**
	 * Receive a single drill packet
	 *
	 * @param Cancellation|null $cancellation An optional cancellation token (timeout?)
	 *
	 * @return AbstractDrillPacket|null The received packet or `null` if the connection was closed
	 */
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

	/** Send a drill packet */
	public function send(AbstractDrillPacket $packet): void {
		$this->logger->debug('Sending Drill packet to {url}: {packet}', [
			'url' => $this->uri,
			'packet' => $packet,
		]);
		$this->connection->sendBinary($packet->toString());
	}

	/**
	 * Parse a binary drill packet into a proper PHP packet
	 *
	 * @param string $payload The binary data to parse
	 *
	 * @throws UnsupportedPacketException if the packet type is unknown
	 */
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
