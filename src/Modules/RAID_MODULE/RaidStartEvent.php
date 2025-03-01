<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'raid(start)')]
class RaidStartEvent extends RaidEvent {
}
