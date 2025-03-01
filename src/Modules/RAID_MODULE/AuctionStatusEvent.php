<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'auction(*)')]
abstract class AuctionStatusEvent extends AuctionEvent {
	/** @param ?string $sender  If set, this is the person ending or cancelling an auction */
	public function __construct(
		Auction $auction,
		public ?string $sender=null,
	) {
		parent::__construct(auction: $auction);
	}
}
