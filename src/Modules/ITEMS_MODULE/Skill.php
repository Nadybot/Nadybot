<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class Skill extends DBRow {
	public function __construct(
		public int $id,
		public string $name,
		public string $unit,
	) {
	}
}
