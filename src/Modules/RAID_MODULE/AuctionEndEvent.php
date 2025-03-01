<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'auction(end)')]
class AuctionEndEvent extends AuctionStatusEvent {
}
