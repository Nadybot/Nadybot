<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'websocket(*)')]
abstract class WebsocketEvent {
	abstract public function getData(): object;
}
