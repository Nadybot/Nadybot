<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use Nadybot\Core\DBRow;

class ArulSabaBuffs extends DBRow {
	public function __construct(
		public string $name,
		public int $min_level,
		public int $left_aoid,
		public int $right_aoid,
	) {
	}
}
