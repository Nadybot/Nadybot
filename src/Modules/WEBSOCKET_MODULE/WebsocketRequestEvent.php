<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'websocket(request)')]
class WebsocketRequestEvent extends WebsocketEvent {
	public function __construct(
		private NadyRequest $data,
	) {
	}

	public function getData(): NadyRequest {
		return $this->data;
	}
}
