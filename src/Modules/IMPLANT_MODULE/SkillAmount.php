<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;
use Nadybot\Core\Types\Skill;

class SkillAmount extends DBRow {
	public function __construct(
		public Skill $skill,
		public int $amount,
	) {
	}
}
