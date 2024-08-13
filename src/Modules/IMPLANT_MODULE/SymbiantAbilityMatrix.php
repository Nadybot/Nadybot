<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'symbiant_ability_matrix', shared: Shared::Yes)]
class SymbiantAbilityMatrix extends DBTable {
	public function __construct(
		public int $symbiant_id,
		public int $ability_id,
		public int $amount,
	) {
	}
}
