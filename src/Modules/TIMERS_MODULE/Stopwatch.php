<?php

namespace Budabot\Modules\TIMERS_MODULE;

/**
 * An object representing a running stopwatch
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
class Stopwatch {
	/**
	 * @var int $start
	 */
	public $start;

	/**
	 * @var StopwatchLap[] $laps
	 */
	public $laps = [];

	/**
	 * @var int $end
	 */
	public $end;

	public function __construct() {
		$this->start = time();
	}

	/**
	 * Get a textual representation of the timer
	 *
	 * @return string
	 */
	public function toString() {
		$descr = "Start:    " . strftime('%Y-%m-%d %H:%M:%S', $this->start) . "\n";
		$last = $this->start;
		foreach ($this->laps as $lap) {
			$descr .= $lap->toString($last);
			$last = $lap->time;
		}
		if ($this->end !== null) {
			$descr .= "End:    +" . strftime('%M:%S', $this->end - $last) . "\n";
		} else {
			$descr .= "Now:   +" . strftime('%M:%S', time() - $last) . "\n";
		}
		return $descr;
	}
}
