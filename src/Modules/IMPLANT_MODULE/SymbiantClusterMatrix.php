<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'symbiant_cluster_matrix', shared: Shared::Yes)]
class SymbiantClusterMatrix extends DBTable {
	public function __construct(
		public int $symbiant_id,
		public int $cluster_id,
		public int $amount,
	) {
	}
}
