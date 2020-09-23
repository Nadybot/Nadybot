<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\Event;

class TimerEvent extends Event {
	public Timer $timer;
}
