<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Websocket\Client\WebsocketConnection;

class DrillConnectEvent extends DrillEvent {
	public const EVENT_MASK = 'drill(connect)';

	public function __construct(
		public WebsocketConnection $client,
	) {
		$this->type = self::EVENT_MASK;
	}
}
