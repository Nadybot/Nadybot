<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\DBRow;

class PerkLevelBuff extends DBRow {
	public int $skill_id;
	public string $skill_name;
	public int $amount;
	public string $unit;
}
