<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use DateTime;

/**
 * An object representing a lap of a stopwatch with Time and optional name
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
class StopwatchLap {
	public DateTime $time;

	public ?string $name;

	public function __construct(?string $name=null) {
		$this->time = new DateTime();
		$this->name = strlen($name??"") ? $name : null;
	}

	/** Get a textual representation of the lap relative to timestamp $last */
	public function toString(DateTime $last): string {
		$descr = "Lap:    +" . $this->time->diff($last)->format('%I:%S');
		if (isset($this->name)) {
			$descr .= " ({$this->name})";
		}
		$descr .= "\n";
		return $descr;
	}
}
