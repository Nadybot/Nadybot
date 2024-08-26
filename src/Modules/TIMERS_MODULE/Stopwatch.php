<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use DateTimeInterface;
use Safe\DateTimeImmutable;

/**
 * An object representing a running stopwatch
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
class Stopwatch {
	public DateTimeInterface $start;

	/** @var list<StopwatchLap> */
	public array $laps = [];

	public ?DateTimeInterface $end = null;

	public function __construct(
		?DateTimeInterface $start=null,
	) {
		$this->start = $start ?? new DateTimeImmutable();
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
			$descr .= 'Now:   +' . (new DateTimeImmutable())->diff($last)->format('%I:%S');
		}
		return $descr;
	}
}
