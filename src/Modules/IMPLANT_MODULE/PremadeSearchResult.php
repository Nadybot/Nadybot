<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\{DBRow, Profession};

class PremadeSearchResult extends DBRow {
	public function __construct(
		public string $slot,
		public Profession $profession,
		public string $ability,
		public string $shiny,
		public string $bright,
		public string $faded,
	) {
	}
}
