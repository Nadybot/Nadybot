<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBRow;
use Nadybot\Modules\ITEMS_MODULE\Skill;

class PerkLevelBuff extends DBRow {
	public int $perk_level_id;
	public int $skill_id;
	public int $amount;
	#[NCA\DB\Ignore]
	public Skill $skill;
}
