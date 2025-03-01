<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\Types\Skill;

class SkillCount extends DBRow {
	public function __construct(
		public int $amount,
		public Skill $skill,
	) {
	}
}
