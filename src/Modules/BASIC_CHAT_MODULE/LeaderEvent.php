<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'leader(*)')]
abstract class LeaderEvent {
	/** @param string $player The names of the new/old leader */
	public function __construct(
		public string $player,
	) {
	}
}
