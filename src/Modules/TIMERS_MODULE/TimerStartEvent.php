<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'timer(start)')]
class TimerStartEvent extends TimerEvent {
}
