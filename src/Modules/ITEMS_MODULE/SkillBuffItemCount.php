<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\Types\Skill;

class SkillBuffItemCount extends DBRow {
	public function __construct(
		public Skill $skill,
		public int $num,
	) {
	}
}
