<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncTimerEvent extends SyncEvent {
	public const EVENT_MASK = 'sync(timer)';

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
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
