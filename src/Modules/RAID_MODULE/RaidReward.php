<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'raid_reward')]
class RaidReward extends DBTable {
	/** The ID of the raid reward */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $name   The primary name how to address this reward
	 * @param int            $points How many points does this reward give
	 * @param string         $reason Which reason to log when giving this reward
	 * @param ?UuidInterface $id     The ID of the raid reward
	 */
	public function __construct(
		public string $name,
		public int $points,
		public string $reason,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
