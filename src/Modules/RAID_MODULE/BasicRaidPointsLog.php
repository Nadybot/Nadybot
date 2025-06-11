<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class BasicRaidPointsLog extends DBRow {
	/**
	 * @param string $username Name of the main character for this log entry
	 * @param int    $delta    How many points were given or taken
	 */
	public function __construct(
		public string $username,
		public int $delta,
	) {
	}
}
