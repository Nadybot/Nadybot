<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class WebsocketRequestEvent extends WebsocketEvent {
	/**
	 * @var NadyRequest
	 *
	 * @psalm-suppress NonInvariantDocblockPropertyType
	 */
	public object $data;
}
