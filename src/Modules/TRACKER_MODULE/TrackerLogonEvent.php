<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'tracker(logon)')]
class TrackerLogonEvent extends TrackerEvent {
}
