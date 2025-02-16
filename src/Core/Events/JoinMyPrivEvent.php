<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Someone joins our private channel */
#[Event(mask: 'joinpriv')]
class JoinMyPrivEvent extends JoinPrivEvent {
}
