<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'effect_value', shared: Shared::Yes)]
class EffectValue extends DBTable {
	public function __construct(
		#[PK] public int $effect_id,
		public string $name,
		public int $q200_value,
	) {
	}
}
