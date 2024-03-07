<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Websocket\Client\WebsocketConnection;
use Nadybot\Core\Event;

class DrillEvent extends Event {
	public WebsocketConnection $client;
}
