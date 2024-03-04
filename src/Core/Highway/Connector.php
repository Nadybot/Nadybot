<?php declare(strict_types=1);

namespace Nadybot\Core\Highway;

use Amp\Cancellation;
use Amp\Websocket\Client\{Rfc6455Connector, WebsocketHandshake};

use Nadybot\Core\Registry;

class Connector {
	public function __construct(
		private Rfc6455Connector $wsConnector
	) {
	}

	public function connect(
		WebsocketHandshake $handshake,
		?Cancellation $cancellation=null
	): ?Connection {
		$wsConnection = $this->wsConnector->connect($handshake, $cancellation);
		$connection = new Connection($wsConnection);
		Registry::injectDependencies($connection);
		return $connection;
	}
}
