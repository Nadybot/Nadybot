<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\Event;
use Nadybot\Core\WebsocketServerConnection;

class WebsocketNadyEvent extends Event {
	public WebsocketServerConnection $websocket;
	public object $data;
}
