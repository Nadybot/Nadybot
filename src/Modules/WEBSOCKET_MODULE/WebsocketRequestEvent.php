<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class WebsocketRequestEvent extends WebsocketNadyEvent {
	public /** @var NadyRequest */ object $data;
}
