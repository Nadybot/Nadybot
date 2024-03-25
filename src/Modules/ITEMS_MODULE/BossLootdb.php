<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class BossLootdb extends DBRow {
	/**
	 * @param int    $bossid   The internal ID of the boss for this loot
	 * @param string $itemname Full name of this item
	 * @param int    $aoid     The internal ID of this item
	 */
	public function __construct(
		public int $bossid,
		public string $itemname,
		public int $aoid,
		#[NCA\DB\Ignore] public ?AODBEntry $item=null,
	) {
	}
}
