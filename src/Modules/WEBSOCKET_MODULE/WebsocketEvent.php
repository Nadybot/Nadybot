<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\Event;

abstract class WebsocketEvent extends Event {
	public const EVENT_MASK = "websocket(*)";

	public object $data;
}
