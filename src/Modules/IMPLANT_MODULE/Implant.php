<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Util;

class Implant {
	/** Jobe clusters can only be inserted into implants of at least this QL */
	public const JOBE_MIN_QL = 100;

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

	/**
	 * The amount of the cluster's tradeskill needed to insert it into an implant
	 *
	 * RK clusters need Nanoprogramming: 1 x multiplier x QL for faded,
	 * 1.5 x for bright and 2 x for shiny, with the multiplier being a
	 * property of the cluster, between 1.0 (e.g. Heavy Weapons) and
	 * 3.0 (Nano Pool).
	 * Jobe clusters need a tradeskill depending on the cluster instead:
	 * 3.25 x QL for faded, 4.75 x for bright and 6.25 x for shiny,
	 * except for Nano Delta with 2.75 x, 4 x and 5.25 x.
	 *
	 * The factors per grade are stored in the cluster table. The requirement
	 * scales linearly, also for refined implants above QL 200, and is rounded
	 * to the nearest integer.
	 *
	 * @param int $ql The QL of the implant, not of the cluster
	 *
	 * @see https://ao.tinkeringidiot.com/ao/plants
	 * @see https://www.ao-universe.com/guides/classic-ao/tradeskill-guides-6/general-crafting-5/implant-creation
	 */
	public static function getRequiredSkill(Cluster $cluster, ClusterGrade $grade, int $ql): int {
		return self::getRequiredSkillForFactor($cluster->getRequirementFactor($grade), $ql);
	}

	/**
	 * The amount of skill needed to insert a cluster with the given factor into an implant
	 *
	 * @param float $factor The factor of the implant QL, see Cluster::getRequirementFactor()
	 * @param int   $ql     The QL of the implant, not of the cluster
	 */
	public static function getRequiredSkillForFactor(float $factor, int $ql): int {
		// Guard against float inaccuracies, so e.g. 3.225 x 20 is always rounded like 64.5
		return (int)round(round($factor * $ql, 6));
	}
}
