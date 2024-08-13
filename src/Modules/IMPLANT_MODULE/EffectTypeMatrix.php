<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'effect_type_matrix', shared: Shared::Yes)]
class EffectTypeMatrix extends DBTable {
	public function __construct(
		#[PK] public int $id,
		public string $name,
		public int $min_val_low,
		public int $max_val_low,
		public int $min_val_high,
		public int $max_val_high,
	) {
	}
}
