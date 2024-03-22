<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

class AuctionBidEvent extends AuctionEvent {
	public const EVENT_MASK = 'auction(bid)';

	public function __construct(
		public Auction $auction,
	) {
		$this->type = self::EVENT_MASK;
	}
}
