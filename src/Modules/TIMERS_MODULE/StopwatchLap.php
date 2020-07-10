<?php

namespace Budabot\Modules\TIMERS_MODULE;

/**
 * An object representing a lap of a stopwatch with Time and optional name
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
class StopwatchLap {
	/**
	 * @var int $time
	 */
	public $time;

	/**
	 * @var string $name
	 */
	public $name;

	public function __construct($name=null) {
		$this->time = time();
		$this->name = strlen($name) ? $name : null;
	}

	/**
	 * Get a textual representation of the lap relative to timestamp $last
	 *
	 * @param int $last
	 * @return string
	 */
	public function toString($last) {
		$descr = "Lap:    +" . strftime('%M:%S', $this->time - $last);
		if (isset($this->name)) {
			$descr .= " ($this->name)";
		}
		$descr .= "\n";
		return $descr;
	}
}
