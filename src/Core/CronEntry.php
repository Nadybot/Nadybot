<?php declare(strict_types=1);

namespace Nadybot\Core;

/** This represents a handler to run periodically by the bot */
class CronEntry {
	/**
	 * If the cron was moved back in time, its call needs to be delayed.
	 * This is the handle for delaying/moving the next call, or
	 * `null` if it's not being moved.
	 */
	public ?string $moveHandle = null;

	/**
	 * @param int         $time      The number of seconds between each run
	 * @param string      $filename  The call handler to run whenever this cron is due
	 * @param int         $nextEvent The UNIX time stamp when to next run `$filename`
	 * @param null|string $handle    The event loop handle to cancel this cron entry
	 */
	public function __construct(
		public int $time,
		public string $filename,
		public int $nextEvent,
		public ?string $handle=null
	) {
	}
}
