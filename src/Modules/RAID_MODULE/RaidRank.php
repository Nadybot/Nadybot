<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidRank extends DBRow {
	public function __construct(
		public string $name,
		public int $rank,
	) {
	}
}
