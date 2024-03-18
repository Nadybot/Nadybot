<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

class TimerStartEvent extends TimerEvent {
	public const EVENT_MASK = "timer(start)";

	public function __construct(
		public Timer $timer,
	) {
		$this->type = self::EVENT_MASK;
	}
}
