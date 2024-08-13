<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'implant_type', shared: Shared::Yes)]
class ImplantType extends DBTable {
	public function __construct(
		#[PK] public int $implant_type_id,
		public string $name,
		public string $short_name,
	) {
	}
}
