<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Event;

abstract class AuctionEvent extends Event {
	public const EVENT_MASK = "auction(*)";

	/** The auction */
	public Auction $auction;
}
