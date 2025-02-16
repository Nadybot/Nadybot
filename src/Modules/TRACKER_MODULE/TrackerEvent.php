<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'tracker(*)')]
abstract class TrackerEvent {
	public function __construct(
		public string $player,
		public int $uid,
	) {
	}
}
