<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class AbilityAmount extends DBRow {
	public function __construct(
		public string $name,
		public int $amount,
	) {
	}
}
