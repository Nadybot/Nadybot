<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

class WhatLocks extends AODBEntry {
	public int $item_id;
	public int $skill_id;
	public int $duration;

	#[NCA\DB\Ignore]
	public ?AODBEntry $item = null;
}
