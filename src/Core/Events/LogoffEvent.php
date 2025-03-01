<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** A character on our buddylist logs off */
#[Event(mask: 'logoff')]
class LogoffEvent extends UserStateEvent {
}
