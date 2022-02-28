<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

class RaidLootSearch extends RaidLoot {
	public ?AODBEntry $item = null;
}
