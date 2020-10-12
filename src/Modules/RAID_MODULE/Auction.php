<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Modules\RAFFLE_MODULE\RaffleItem;

class Auction {
	/** The item currently being auctioned */
	public RaffleItem $item;

	/** The person auctioning the item */
	public string $auctioneer;

	/** The current bid */
	public int $bid = 0;

	/** The current maximum bid */
	public int $max_bid = 0;

	/** The current top bidder */
	public ?string $top_bidder = null;

	/** UNIX timestamp when the auction ends */
	public int $end = 0;
}
