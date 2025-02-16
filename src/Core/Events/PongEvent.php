<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'pong')]
class PongEvent {
	/** @param string $worker Which worker received the pong */
	public function __construct(
		public string $worker,
	) {
	}
}
