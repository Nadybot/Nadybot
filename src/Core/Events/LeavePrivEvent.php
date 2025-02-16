<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** We are leaving a private channel */
#[Event(mask: 'extleavepriv')]
class LeavePrivEvent extends JoinLeaveEvent {
}
