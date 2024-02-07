<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use function Amp\call;

use Amp\Websocket\Client\{Connection as WsConnection, Handshake, Rfc6455Connector};
use Amp\{CancellationToken, Promise};

use Generator;
use Nadybot\Core\Registry;

class Connector {
	public function __construct(
		private Rfc6455Connector $wsConnector
	) {
	}

	/** @return Promise<?Connection> */
	public function connect(Handshake $handshake, ?CancellationToken $cancellationToken=null): Promise {
		return call(function () use ($handshake, $cancellationToken): Generator {
			/** @var WsConnection */
			$wsConnection = yield $this->wsConnector->connect($handshake, $cancellationToken);
			$connection = new Connection($wsConnection);
			Registry::injectDependencies($connection);
			return $connection;
		});
	}
}
