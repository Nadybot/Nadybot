<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;
use Ramsey\Uuid\UuidInterface;

class RaidHistoryEntry extends DBRow {
	public function __construct(
		public UuidInterface $raid_id,
		public int $started,
		public int $stopped,
		public int $raiders,
		public int $points,
	) {
	}
}
