<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	DBRow,
	Types\Skill,
};

class SkillBuffItemCount extends DBRow {
	public function __construct(
		#[NCA\DB\MapRead([Skill::class, 'tryFrom'])] public ?Skill $skill,
		public int $num,
	) {
	}
}
