<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Someone leaves another bot's private channel */
#[Event(mask: 'otherleavepriv')]
class OtherLeavePrivEvent extends JoinLeaveEvent {
}
