<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{ColName, PK, Shared, Table};
use Nadybot\Core\DBTable;
use Nadybot\Core\Types\Skill;

#[Table(name: 'cluster', shared: Shared::Yes)]
class Cluster extends DBTable {
	public function __construct(
		#[PK] public int $cluster_id,
		public int $effect_type_id,
		public string $long_name,
		public string $official_name,
		public int $np_req,
		#[ColName('skill_id')] public ?Skill $skill=null,
	) {
	}
}
