<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(timer)')]
class SyncTimerEvent extends SyncEvent {
	/**
	 * @param string $name     Name of the timer
	 * @param string $owner    Character who created the timer
	 * @param int    $endtime  Timestamp when this timer goes off
	 * @param int    $settime  Timestamp when this timer was set
	 * @param ?int   $interval If set, this is a repeating timer and this is the interval
	 */
	public function __construct(
		public string $name,
		public string $owner,
		public int $endtime,
		public int $settime,
		public ?int $interval=null,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct(
			sourceBot: $sourceBot,
			sourceDimension: $sourceDimension,
			forceSync: $forceSync,
		);
	}
}
