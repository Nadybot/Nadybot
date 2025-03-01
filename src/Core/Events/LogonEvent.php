<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** A character on our buddylist logs on */
#[Event(mask: 'logon')]
class LogonEvent extends UserStateEvent {
}
