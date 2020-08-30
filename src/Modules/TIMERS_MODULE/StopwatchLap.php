<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

/**
 * An object representing a lap of a stopwatch with Time and optional name
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
class StopwatchLap {
	public int $time;

	public ?string $name;

	public function __construct($name=null) {
		$this->time = time();
		$this->name = strlen($name) ? $name : null;
	}

	/**
	 * Get a textual representation of the lap relative to timestamp $last
	 */
	public function toString(int $last): string {
		$descr = "Lap:    +" . strftime('%M:%S', $this->time - $last);
		if (isset($this->name)) {
			$descr .= " ($this->name)";
		}
		$descr .= "\n";
		return $descr;
	}
}
