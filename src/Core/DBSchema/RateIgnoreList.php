<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class RateIgnoreList extends DBRow {
	public function __construct(
		public string $name,
		public string $added_by,
		public int $added_dt,
	) {
	}
}
