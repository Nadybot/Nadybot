<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Event;

class AuctionEvent extends Event {
	/** The auction */
	public Auction $auction;

	/** If set, this is the person ending or cancelling an auction */
	public string $sender;
}
