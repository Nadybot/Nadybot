<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'timer(del)')]
final class TimerDelEvent extends TimerEvent {
}
