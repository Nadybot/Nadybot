<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'auction(*)')]
abstract class AuctionEvent {
	/** @param Auction $auction The auction */
	public function __construct(
		public Auction $auction,
	) {
	}
}
