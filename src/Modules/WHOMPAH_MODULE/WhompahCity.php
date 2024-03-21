<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE;

use Nadybot\Core\{DBRow, Faction};

class WhompahCity extends DBRow {
	public function __construct(
		public int $id,
		public string $city_name,
		public string $zone,
		public Faction $faction,
		public string $short_name,
	) {
	}
}
