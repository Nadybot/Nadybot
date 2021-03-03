<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

class PerkLevelAction extends DBRow {
	public int $perk_level_id;
	/** @db:ignore */
	public ?int $perk_level;
	public int $action_id;
	public bool $scaling = false;
	public ?AODBEntry $aodb;
}
