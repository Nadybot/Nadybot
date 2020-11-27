<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidPoints extends DBRow {
	public string $username;
	public int $points;
}
