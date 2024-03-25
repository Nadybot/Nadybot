<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class LadderRequirements extends DBRow {
	public function __construct(
		public int $ql,
		public int $treatment,
		public int $ability,
		public int $abilityShiny,
		public int $abilityBright,
		public int $abilityFaded,
		public int $skillShiny,
		public int $skillBright,
		public int $skillFaded,
		public int $lowestAbilityShiny,
		public int $lowestAbilityBright,
		public int $lowestAbilityFaded,
		public int $lowestSkillShiny,
		public int $lowestSkillBright,
		public int $lowestSkillFaded,
	) {
	}
}
