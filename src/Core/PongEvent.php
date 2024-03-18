<?php declare(strict_types=1);

namespace Nadybot\Core;

class PongEvent extends Event {
	public const EVENT_MASK = "pong";

	/** @param string $worker Which worker received the pong */
	public function __construct(
		public string $worker,
	) {
		$this->type = self::EVENT_MASK;
	}
}
