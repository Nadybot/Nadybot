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

	/**
	 * The amount of Nanoprogramming needed to insert a cluster into an implant
	 *
	 * For RK clusters, this depends on the skill being implanted:
	 * Faded:  1   x multiplier x QL
	 * Bright: 1.5 x multiplier x QL
	 * Shiny:  2   x multiplier x QL
	 * where the multiplier is a property of the cluster: np_req divided by 400.
	 *
	 * All Jobe clusters need the same amount of Nanoprogramming per grade:
	 * Faded:  3.25 x QL
	 * Bright: 4.75 x QL
	 * Shiny:  6.25 x QL
	 * with Nano Delta (Nano Regeneration) clusters being the only exception:
	 * those need 2.75x, 4x and 5.25x respectively.
	 *
	 * @param null|Cluster $cluster The RK cluster to insert. Required if !$jobe,
	 *                              because the requirement varies by the implanted skill
	 */
	public static function getRequiredNP(bool $jobe, ClusterGrade $grade, int $ql, ?Cluster $cluster=null): int {
		if ($jobe) {
			$multiplier = match ($grade) {
				ClusterGrade::Shiny => 6.25,
				ClusterGrade::Bright => 4.75,
				ClusterGrade::Faded => 3.25,
			};
			return (int)floor($ql * $multiplier);
		}
		if (!isset($cluster)) {
			throw new InvalidArgumentException(
				'The Nanoprogramming requirement for RK clusters depends on the implanted skill, so a cluster is needed'
			);
		}
		return self::getRequiredNPForNpReq($cluster->np_req, $grade, $ql);
	}

	/**
	 * The amount of Nanoprogramming needed to insert a cluster with the
	 * given np_req into an implant
	 *
	 * The npReq is the Nanoprogramming needed to insert the shiny of a skill
	 * into a QL 200 implant (2 x multiplier x 200) and scales linearly with
	 * the QL of the implant, also for refined implants above QL 200.
	 */
	public static function getRequiredNPForNpReq(int $npReq, ClusterGrade $grade, int $ql): int {
		$multiplier = match ($grade) {
			ClusterGrade::Shiny => 2,
			ClusterGrade::Bright => 1.5,
			ClusterGrade::Faded => 1,
		};
		return (int)floor($npReq * $multiplier * $ql / 400);
	}
}
