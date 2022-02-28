<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBRow;

class BossLootdb extends DBRow {
	/** The internal ID of the boss for this loot */
	public int $bossid;

	/** Full name of this item */
	public string $itemname;

	/** The internal ID of this item */
	public int $aoid;

	#[NCA\DB\Ignore]
	public ?AODBEntry $item = null;
}
