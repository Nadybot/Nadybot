<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

class WebsocketSubscribeEvent extends WebsocketEvent {
	public const EVENT_MASK = "websocket(subscribe)";

	/**
	 * @var NadySubscribe
	 *
	 * @psalm-suppress NonInvariantDocblockPropertyType
	 */
	public object $data;

	public function __construct(
		NadySubscribe $data,
	) {
		$this->data = $data;
		$this->type = self::EVENT_MASK;
	}
}
