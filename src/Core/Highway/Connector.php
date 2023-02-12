<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use function Amp\call;

use Amp\Websocket\Client\{Connection as WsConnection, Handshake, Rfc6455Connector};
use Amp\{CancellationToken, Promise};

use Generator;

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
			return new Connection($wsConnection);
		});
	}
}
