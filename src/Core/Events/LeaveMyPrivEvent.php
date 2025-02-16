<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Someone leaves our private channel */
#[Event(mask: 'leavepriv')]
class LeaveMyPrivEvent extends LeavePrivEvent {
}
