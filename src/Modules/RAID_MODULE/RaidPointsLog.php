<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidPointsLog extends DBRow {
	/** Name of the main character for this log entry */
	public string $username;

	/** How many points were given or taken */
	public int $delta;

	/** When did this happen */
	public int $time;

	/** Who gave or took points? */
	public string $changed_by;

	/** Was this change for this player only? */
	public bool $individual;

	/** Why were points given  or taken? */
	public string $reason = 'unknown';

	/** Are these points for simple raid participation? */
	public bool $ticker;

	/** If points were given during a raid, which raid was it? */
	public ?int $raid_id;
}
