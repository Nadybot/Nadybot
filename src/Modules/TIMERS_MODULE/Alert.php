<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

final class Alert {
	/**
	 * @param string              $message The message to display for this alert
	 * @param int                 $time    Timestamp when to display this alert
	 * @param array<string,mixed> $extra   Extra data for each alert
	 */
	public function __construct(
		public string $message,
		public int $time,
		public array $extra=[],
	) {
	}
}
