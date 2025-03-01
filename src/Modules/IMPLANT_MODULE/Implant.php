<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Util;

class Implant {
	public static function getRequirement(ImplantRequirement $requirement, bool $jobe, int $ql): int {
		return match ($jobe) {
			false => match ($requirement) {
				ImplantRequirement::Ability => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 6, 404, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 426, 1_095, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ImplantRequirement::Treatment => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 11, 951, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 1_001, 2_051, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ImplantRequirement::TitleLevel => 0,
			},
			true => match ($requirement) {
				ImplantRequirement::Ability => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 16, 414, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 476, 1_231, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ImplantRequirement::Treatment => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 11, 951, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 1_001, 2_051, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ImplantRequirement::TitleLevel => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 3, 4, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 5, 6, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				}
			}
		};
	}

	public static function getBuff(ImplantBuff $buff, ClusterGrade $grade, int $ql): int {
		return match ($buff) {
			ImplantBuff::Skill => match ($grade) {
				ClusterGrade::Faded => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 2, 42, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 42, 57, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ClusterGrade::Bright => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 3, 63, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 63, 85, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ClusterGrade::Shiny => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 6, 105, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 106, 141, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
			},
			ImplantBuff::Ability => match ($grade) {
				ClusterGrade::Faded => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 2, 22, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 22, 29, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ClusterGrade::Bright => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 3, 33, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 33, 44, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
				ClusterGrade::Shiny => match (true) {
					$ql <= 200 => Util::interpolate(1, 200, 5, 55, $ql),
					$ql <= 300 => Util::interpolate(201, 300, 55, 73, $ql),
					default => throw new InvalidArgumentException("\"{$ql}\" is not a valid implant QL."),
				},
			}
		};
	}

	public static function getRequiredNP(bool $jobe, ClusterGrade $grade, int $ql): int {
		$multiplier = match ($jobe) {
			true => match (true) {
				$ql <= 200 => match ($grade) {
					ClusterGrade::Shiny => 6.25,
					ClusterGrade::Bright => 4.75,
					ClusterGrade::Faded => 3.25,
				},
				default => match ($grade) {
					ClusterGrade::Shiny => 6.75,
					ClusterGrade::Bright => 5.25,
					ClusterGrade::Faded => 3.75,
				}
			},
			false => match (true) {
				$ql <= 200 => match ($grade) {
					ClusterGrade::Shiny => 4,
					ClusterGrade::Bright => 3,
					ClusterGrade::Faded => 2,
				},
				default => match ($grade) {
					ClusterGrade::Shiny => 5.25,
					ClusterGrade::Bright => 4.35,
					ClusterGrade::Faded => 2.55,
				}
			},
		};
		return (int)floor($ql * $multiplier);
	}
}
