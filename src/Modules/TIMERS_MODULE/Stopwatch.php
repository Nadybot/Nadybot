<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

/**
 * An object representing a running stopwatch
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
class Stopwatch {
	public int $start;

	/** @var StopwatchLap[] */
	public array $laps = [];

	public int $end;

	public function __construct() {
		$this->start = time();
	}

	/**
	 * Get a textual representation of the timer
	 */
	public function toString(): string {
		$descr = "Start:    " . strftime('%Y-%m-%d %H:%M:%S', $this->start) . "\n";
		$last = $this->start;
		foreach ($this->laps as $lap) {
			$descr .= $lap->toString($last);
			$last = $lap->time;
		}
		if (isset($this->end)) {
			$descr .= "End:    +" . strftime('%M:%S', $this->end - $last) . "\n";
		} else {
			$descr .= "Now:   +" . strftime('%M:%S', time() - $last) . "\n";
		}
		return $descr;
	}
}
