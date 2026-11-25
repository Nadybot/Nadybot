<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{ColName, PK, Shared, Table};
use Nadybot\Core\DBTable;
use Nadybot\Core\Types\Skill;

#[Table(name: 'cluster', shared: Shared::Yes)]
class Cluster extends DBTable {
	/**
	 * @param ?Skill $skill      The skill this cluster buffs
	 * @param ?Skill $tradeskill The skill needed to insert this cluster into an implant:
	 *                           Nanoprogramming for RK clusters, a skill depending
	 *                           on the cluster for Jobe clusters instead
	 * @param bool   $jobe       Whether this is a Jobe cluster
	 * @param float  $req_faded  A faded cluster needs this x implant QL of $tradeskill
	 * @param float  $req_bright A bright cluster needs this x implant QL of $tradeskill
	 * @param float  $req_shiny  A shiny cluster needs this x implant QL of $tradeskill
	 */
	public function __construct(
		#[PK] public int $cluster_id,
		public int $effect_type_id,
		public string $long_name,
		public string $official_name,
		#[ColName('skill_id')] public ?Skill $skill,
		#[ColName('tradeskill_id')] public ?Skill $tradeskill,
		public bool $jobe,
		public float $req_faded,
		public float $req_bright,
		public float $req_shiny,
	) {
	}

	/** Get the factor of the implant QL needed of the tradeskill to insert this cluster */
	public function getRequirementFactor(ClusterGrade $grade): float {
		return match ($grade) {
			ClusterGrade::Faded => $this->req_faded,
			ClusterGrade::Bright => $this->req_bright,
			ClusterGrade::Shiny => $this->req_shiny,
		};
	}
}
