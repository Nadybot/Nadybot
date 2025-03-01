<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Initialize and set up instances */
#[Event(mask: 'setup')]
class SetupEvent {
}
