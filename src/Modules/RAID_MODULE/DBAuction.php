<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class DBAuction extends DBRow {
	/**
	 * @param string  $item       Item that was auctioned
	 * @param string  $auctioneer Person auctioning the item
	 * @param ?int    $cost       The cost that was paid by the winner or null if no winner
	 * @param ?string $winner     Name of the winner of the auction or null if none
	 * @param int     $end        UNIX timestamp when the auction was over
	 * @param bool    $reimbursed Has the person who won this auction been reimbursed for accidental bidding?
	 */
	public function __construct(
		public string $item,
		public string $auctioneer,
		public ?int $cost,
		public ?string $winner,
		public int $end,
		public bool $reimbursed,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
