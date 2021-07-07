<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @Instance
 */
class EventHub {
	public function registerEventReceiver(): self {
		return $this;
	}

	public function registerEventEmitter(): self {
		return $this;
	}
}
