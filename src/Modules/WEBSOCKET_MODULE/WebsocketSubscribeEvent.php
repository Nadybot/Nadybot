<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'websocket(subscribe)')]
class WebsocketSubscribeEvent extends WebsocketEvent {
	public function __construct(
		private NadySubscribe $data,
	) {
	}

	public function getData(): NadySubscribe {
		return $this->data;
	}
}
