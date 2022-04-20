<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidStatus extends DBRow {
	public int $time;
	public int $status;
}
