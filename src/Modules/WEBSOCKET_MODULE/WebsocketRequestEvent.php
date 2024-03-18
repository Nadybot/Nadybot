<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class WebsocketRequestEvent extends WebsocketEvent {
	public const EVENT_MASK = "websocket(request)";

	/**
	 * @var NadyRequest
	 *
	 * @psalm-suppress NonInvariantDocblockPropertyType
	 */
	public object $data;

	public function __construct(
		NadyRequest $data,
	) {
		$this->data = $data;
		$this->type = self::EVENT_MASK;
	}
}
