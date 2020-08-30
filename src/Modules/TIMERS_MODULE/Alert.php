<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use stdClass;

class Alert extends stdClass {
	/** The message to display for this alert */
	public string $message;

	/** Timestamp when to display this alert */
	public int $time;
}
