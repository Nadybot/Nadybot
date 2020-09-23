<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class WebsocketSubscribeEvent extends WebsocketEvent {
	public /** @var NadySubscribe */ object $data;
}
