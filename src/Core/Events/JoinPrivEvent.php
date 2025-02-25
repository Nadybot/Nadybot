<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** We join another bot's private channel */
#[Event(mask: 'extjoinpriv')]
class JoinPrivEvent extends JoinLeaveEvent {
}
