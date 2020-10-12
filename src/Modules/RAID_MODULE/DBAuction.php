<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class DBAuction extends DBRow {
	public int $id;

	/** Item that was auctioned */
	public string $item;

	/** Person auctioning the item */
	public string $auctioneer;

	/** The cost that was paid by the winner or null if no winner */
	public ?int $cost;

	/** Name of the winner of the auction or null if none */
	public ?string $winner;

	/** UNIX timestamp when the auction was over */
	public int $end;

	/** Has the person who won this auction been reimbursed for accidental bidding? */
	public bool $refunded;
}
