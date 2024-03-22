<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

abstract class AuctionStatusEvent extends AuctionEvent {
	public const EVENT_MASK = 'auction(*)';

	/** The auction */
	public Auction $auction;

	/** If set, this is the person ending or cancelling an auction */
	public ?string $sender;
}
