<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidPointsLog extends DBRow {
	/**
	 * @param string $username   Name of the main character for this log entry
	 * @param int    $delta      How many points were given or taken
	 * @param int    $time       When did this happen
	 * @param string $changed_by Who gave or took points?
	 * @param bool   $individual Was this change for this player only?
	 * @param string $reason     Why were points given  or taken?
	 * @param bool   $ticker     Are these points for simple raid participation?
	 * @param ?int   $raid_id    If points were given during a raid, which raid was it?
	 */
	public function __construct(
		public string $username,
		public int $delta,
		public int $time,
		public string $changed_by,
		public bool $individual,
		public string $reason,
		public bool $ticker,
		public ?int $raid_id,
	) {
	}
}
