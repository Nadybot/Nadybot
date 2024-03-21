<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

class PerkLevelAction extends DBRow {
	public function __construct(
		#[NCA\DB\Ignore]
		public ?int $perk_level,
		public int $action_id,
		public bool $scaling=false,
		#[NCA\DB\Ignore]
		public ?AODBEntry $aodb=null,
		public ?int $perk_level_id=null,
	) {
	}
}
