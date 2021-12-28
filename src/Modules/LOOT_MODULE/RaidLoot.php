<?php

declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

class RaidLoot extends DBRow {
	public int $id;
	public string $raid;
	public string $category;
	public int $ql;
	public string $name;
	public string $comment;
	public int $multiloot;
	public ?int $aoid=null;
	/** @db:ignore */
	public ?AODBEntry $item=null;
}
