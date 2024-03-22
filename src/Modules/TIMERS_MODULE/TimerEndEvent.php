<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

class TimerEndEvent extends TimerEvent {
	public const EVENT_MASK = 'timer(stop)';

	public function __construct(
		public Timer $timer,
	) {
		$this->type = self::EVENT_MASK;
	}
}
