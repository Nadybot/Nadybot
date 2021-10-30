<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncTimerEvent extends SyncEvent {
	public string $type = "sync(timer)";

	/** Name of the timer */
	public string $name;

	/** Character who created the timer */
	public string $owner;

	/** Timestamp when this timer goes off */
	public int $endtime;

	/** Timestamp when this timer was set */
	public int $settime;

	/** If set, this is a repeating timer and this is the interval */
	public ?int $interval = null;
}
