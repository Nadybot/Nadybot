<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'premade_implant', shared: Shared::Yes)]
class PremadeImplant extends DBTable {
	public function __construct(
		public int $implant_type_id,
		public int $profession_id,
		public int $ability_id,
		public int $shiny_cluster_id,
		public int $bright_cluster_id,
		public int $faded_cluster_id,
	) {
	}
}
