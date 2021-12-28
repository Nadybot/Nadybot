<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

class PerkLevelBuff extends DBRow {
	public int $perk_level_id;
	public int $skill_id;
	public int $amount;
}
