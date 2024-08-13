<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'cluster', shared: Shared::Yes)]
class Cluster extends DBTable {
	public function __construct(
		#[PK] public int $cluster_id,
		public int $effect_type_id,
		public string $long_name,
		public string $official_name,
		public int $np_req,
		public ?int $skill_id=null,
	) {
	}
}
