<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Socket\ConnectContext;
use Amp\Websocket\Client\{Rfc6455Connector, WebsocketConnection, WebsocketHandshake};
use Amp\Websocket\WebsocketClosedException;
use Nadybot\Modules\WEBSERVER_MODULE\Drill;
use Psr\Log\LoggerInterface;

class DrillClient {
	private ?WebsocketConnection $connection = null;

	public function __construct(
		private string $url,
		private LoggerInterface $logger,
		private ?Rfc6455Connector $connector=null,
	) {
	}

	public function connect(): bool {
		$handshake = new WebsocketHandshake($this->url);
		if (!isset($this->connector)) {
			$connectContext = (new ConnectContext())->withTcpNoDelay();
			$httpClient = (new HttpClientBuilder())
				->usingPool(new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $connectContext)))
				->intercept(new RemoveRequestHeader('origin'))
				->build();
			$client = $this->connector = new Rfc6455Connector(httpClient: $httpClient);
		} else {
			$client = $this->connector;
		}
		try {
			$this->logger->info('Connecting to Drill server {url}', ['url' => $this->url]);

			$this->connection = $client->connect($handshake, null);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error('Drill endpoint errored: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			return false;
		}
	}

	public function close(): void {
		$this->connection?->close();
	}

	public function receive(): ?Drill\Packet\Base {
		if (null !== ($message = $this->connection->receive())) {
			$payload = $message->buffer();

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

	public function send(Drill\Packet\Base $packet): void {
		$this->logger->debug('Sending Drill packet to {url}: {packet}', [
			'url' => $this->url,
			'packet' => $packet,
		]);
		$this->connection->sendBinary($packet->toString());
	}

	private function parseDrillMessage(string $payload): Drill\Packet\Base {
		try {
			$packet = Drill\PacketFactory::parse($payload);
		} catch (Drill\UnsupportedPacketException $e) {
			$this->logger->warning('Received unsupported Drill package type {type}', [
				'type' => $e->getMessage(),
				'exception' => $e,
			]);
			throw $e;
		}
		$this->logger->debug('Received Drill packet from {url}: {package}', [
			'url' => $this->url,
			'package' => $packet,
		]);
		return $packet;
	}
}
