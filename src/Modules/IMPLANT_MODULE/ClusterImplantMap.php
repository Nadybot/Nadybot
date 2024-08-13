<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'cluster_implant_map', shared: Shared::Yes)]
class ClusterImplantMap extends DBTable {
	public function __construct(
		public int $implant_type_id,
		public int $cluster_id,
		public int $cluster_type_id,
	) {
	}
}
