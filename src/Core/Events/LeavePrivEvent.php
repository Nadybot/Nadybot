<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** We left another bot's private channel */
#[Event(mask: 'extleavepriv')]
class LeavePrivEvent extends JoinLeaveEvent {
}
