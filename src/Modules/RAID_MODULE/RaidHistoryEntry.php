<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidHistoryEntry extends DBRow {
	public int $raid_id;
	public int $started;
	public int $stopped;
	public int $raiders;
	public int $points;
}
