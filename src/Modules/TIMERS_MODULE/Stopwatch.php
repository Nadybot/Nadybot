<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Safe\DateTime;

/**
 * An object representing a running stopwatch
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
class Stopwatch {
	public DateTime $start;

	/** @var StopwatchLap[] */
	public array $laps = [];

	public ?DateTime $end = null;

	public function __construct() {
		$this->start = new DateTime();
	}

	/** Get a textual representation of the timer */
	public function toString(): string {
		$descr = 'Start:    ' . $this->start->format('Y-M-d H:i:s T') . "\n";
		$last = $this->start;
		foreach ($this->laps as $lap) {
			$descr .= $lap->toString($last);
			$last = $lap->time;
		}
		if (isset($this->end)) {
			$descr .= 'End:    +' . $this->end->diff($last)->format('%I:%S');
		} else {
			$descr .= 'Now:   +' . (new DateTime())->diff($last)->format('%I:%S');
		}
		return $descr;
	}
}
