<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidStat extends DBRow {
	public function __construct(
		public int $raid_id,
		public int $started,
		public string $started_by,
		public string $starter_main,
		public int $num_raiders,
	) {
	}
}
