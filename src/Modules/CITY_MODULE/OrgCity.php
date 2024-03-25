<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\DBRow;

class OrgCity extends DBRow {
	public function __construct(
		public int $time,
		public string $action,
		public string $player,
	) {
	}
}
