<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'implant_requirements', shared: Shared::Yes)]
class LadderRequirements extends DBTable {
	public int $lowestAbilityShiny = -1;
	public int $lowestAbilityBright = -1;
	public int $lowestAbilityFaded = -1;
	public int $lowestSkillShiny = -1;
	public int $lowestSkillBright = -1;
	public int $lowestSkillFaded = -1;

	public function __construct(
		#[PK] public int $ql,
		public int $treatment,
		public int $ability,
		public int $abilityShiny,
		public int $abilityBright,
		public int $abilityFaded,
		public int $skillShiny,
		public int $skillBright,
		public int $skillFaded,
	) {
	}

	public function get(ClusterGrade $grade, LadderType $type): int {
		return match ($type) {
			LadderType::Ability => match ($grade) {
				ClusterGrade::Shiny => $this->abilityShiny,
				ClusterGrade::Bright => $this->abilityBright,
				ClusterGrade::Faded => $this->abilityFaded,
			},
			LadderType::Skill => match ($grade) {
				ClusterGrade::Shiny => $this->skillShiny,
				ClusterGrade::Bright => $this->skillBright,
				ClusterGrade::Faded => $this->skillFaded,
			},
		};
	}

	public function getLowest(ClusterGrade $grade, LadderType $type): int {
		return match ($type) {
			LadderType::Ability => match ($grade) {
				ClusterGrade::Shiny => $this->lowestAbilityShiny,
				ClusterGrade::Bright => $this->lowestAbilityBright,
				ClusterGrade::Faded => $this->lowestAbilityFaded,
			},
			LadderType::Skill => match ($grade) {
				ClusterGrade::Shiny => $this->lowestSkillShiny,
				ClusterGrade::Bright => $this->lowestSkillBright,
				ClusterGrade::Faded => $this->lowestSkillFaded,
			},
		};
	}

	public function setLowest(ClusterGrade $grade, LadderType $type, int $value): int {
		return match ($type) {
			LadderType::Ability => match ($grade) {
				ClusterGrade::Shiny => $this->lowestAbilityShiny = $value,
				ClusterGrade::Bright => $this->lowestAbilityBright = $value,
				ClusterGrade::Faded => $this->lowestAbilityFaded = $value,
			},
			LadderType::Skill => match ($grade) {
				ClusterGrade::Shiny => $this->lowestSkillShiny = $value,
				ClusterGrade::Bright => $this->lowestSkillBright = $value,
				ClusterGrade::Faded => $this->lowestSkillFaded = $value,
			},
		};
	}
}
