<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Amp\Websocket\WebsocketClient;
use Nadybot\Core\Attributes\Event;

#[Event(mask: 'websocket(request)')]
class WebsocketRequestEvent extends WebsocketEvent {
	public function __construct(
		private NadyRequest $data,
		private WebsocketClient $client,
	) {
	}

	public function getData(): NadyRequest {
		return $this->data;
	}

	public function getClient(): WebsocketClient {
		return $this->client;
	}
}
