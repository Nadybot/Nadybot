<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;

/** Fired when the event feed connected successfully */
#[Event(mask: 'event-feed-connect')]
class EventFeedConnect {
}
