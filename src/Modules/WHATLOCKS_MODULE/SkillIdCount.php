<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Modules\ITEMS_MODULE\Skill;

class SkillIdCount extends DBRow {
	public int $skill_id;
	public int $amount;
	public Skill $skill;
}
