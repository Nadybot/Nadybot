<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Nadybot\Modules\ITEMS_MODULE\Skill;

class PerkLevelBuff extends DBRow {
	#[NCA\DB\Ignore]
	public ?Skill $skill=null;

	public function __construct(
		public int $perk_level_id,
		public int $skill_id,
		public int $amount,
	) {
	}
}
