<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class RaidReward extends DBRow {
	/**
	 * @param string $name   The primary name how to address this reward
	 * @param int    $points How many points does this reward give
	 * @param string $reason Which reason to log when giving this reward
	 * @param ?int   $id     The ID of the raid reward
	 */
	public function __construct(
		public string $name,
		public int $points,
		public string $reason,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
