<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{DBRow, Util};
use Nadybot\Core\Types\Skill;

class ImplantInfo extends DBRow {
	public int $ability = 0;
	public int $treatment = 0;

	public function __construct(
		public readonly int $ability_ql1,
		public readonly int $ability_ql200,
		public readonly int $ability_ql201,
		public readonly int $ability_ql300,
		public readonly int $treat_ql1,
		public readonly int $treat_ql200,
		public readonly int $treat_ql201,
		public readonly int $treat_ql300,
		public readonly int $shiny_effect_type_id,
		public readonly int $bright_effect_type_id,
		public readonly int $faded_effect_type_id,
		public readonly Skill $skill,
	) {
	}

	public function getEffectTypeId(ClusterGrade $grade): int {
		return match ($grade) {
			ClusterGrade::Shiny => $this->shiny_effect_type_id,
			ClusterGrade::Bright => $this->bright_effect_type_id,
			ClusterGrade::Faded => $this->faded_effect_type_id,
		};
	}

	public function atQL(int $ql): self {
		if ($ql < 201) {
			$minAbility = $this->ability_ql1;
			$maxAbility = $this->ability_ql200;
			$minTreatment = $this->treat_ql1;
			$maxTreatment = $this->treat_ql200;
			$minQL = 1;
			$maxQL = 200;
		} else {
			$minAbility = $this->ability_ql201;
			$maxAbility = $this->ability_ql300;
			$minTreatment = $this->treat_ql201;
			$maxTreatment = $this->treat_ql300;
			$minQL = 201;
			$maxQL = 300;
		}

		$result = clone $this;
		$result->ability = Util::interpolate($minQL, $maxQL, $minAbility, $maxAbility, $ql);
		$result->treatment = Util::interpolate($minQL, $maxQL, $minTreatment, $maxTreatment, $ql);

		return $result;
	}
}
