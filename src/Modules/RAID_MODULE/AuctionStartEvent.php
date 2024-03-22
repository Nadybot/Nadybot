<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

class AuctionStartEvent extends AuctionStatusEvent {
	public const EVENT_MASK = 'auction(start)';

	public function __construct(
		public Auction $auction,
		public ?string $sender=null,
	) {
		$this->type = self::EVENT_MASK;
	}
}
