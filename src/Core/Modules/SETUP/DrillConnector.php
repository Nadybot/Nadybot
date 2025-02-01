<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SETUP;

use Amp\Http\Client\Connection\{DefaultConnectionFactory, UnlimitedConnectionPool};
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Interceptor\RemoveRequestHeader;
use Amp\Socket\ConnectContext;
use Amp\Websocket\Client\{Rfc6455Connector, WebsocketHandshake};
use League\Uri;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;

class DrillConnector {
	private UriInterface $uri;

	public function __construct(
		UriInterface|string $uri,
		private LoggerInterface $logger,
		private ?Rfc6455Connector $connector=null,
	) {
		if (is_string($uri)) {
			try {
				$uri = Uri\Http::new($uri);
			} catch (\Exception $exception) {
				throw new \ValueError('Invalid Websocket URI provided', 0, $exception);
			}
		}
		$this->uri = $uri;
	}

	public function connect(): DrillConnection {
		$handshake = new WebsocketHandshake($this->uri);
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
			$this->logger->info('Connecting to Drill server {url}', ['url' => $this->uri]);

			return new DrillConnection(
				connection: $client->connect($handshake, null),
				uri: $this->uri,
				logger: $this->logger
			);
		} catch (\Throwable $e) {
			$this->logger->error('Drill endpoint errored: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			throw $e;
		}
	}
}
