<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidReward extends DBRow {
	/** The ID of the raid reward */
	public int $id;
	/** The primary name how to address this reward */
	public string $name;
	/** How many points does this reward give */
	public int $points;
	/** Which reason to log when giving this reward */
	public string $reason;
}
