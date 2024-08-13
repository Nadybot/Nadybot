<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'implant_matrix', shared: Shared::Yes)]
class ImplantMatrix extends DBTable {
	public function __construct(
		#[PK] public int $id,
		public int $shining_id,
		public int $bright_id,
		public int $faded_id,
		public int $ability_id,
		public int $treat_ql1,
		public int $ability_ql1,
		public int $treat_ql200,
		public int $ability_ql200,
		public int $treat_ql201,
		public int $ability_ql201,
		public int $treat_ql300,
		public int $ability_ql300,
	) {
	}
}
