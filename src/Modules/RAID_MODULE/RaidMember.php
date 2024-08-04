<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{Attributes\DB, DBTable};
use Ramsey\Uuid\UuidInterface;

#[DB\Table(name: 'raid_member')]
class RaidMember extends DBTable {
	/** UNIX Timestamp when they joined the raid */
	public int $joined;

	/**
	 * @param UuidInterface $raid_id          ID of the raid this player represents
	 * @param string        $player           Name of the character
	 * @param ?int          $left             UNIX Timestamp when they left the raid/were kicked, null if still in
	 * @param int           $points           How many points have they gotten in this raid
	 * @param int           $pointsRewarded   How many points have they received from rewards in this raid
	 * @param int           $pointsIndividual How many points have they gained/lost individually in this raid
	 * @param ?int          $joined           UNIX Timestamp when they joined the raid
	 */
	public function __construct(
		public UuidInterface $raid_id,
		public string $player,
		public ?int $left=null,
		#[DB\Ignore] public int $points=0,
		#[DB\Ignore] public int $pointsRewarded=0,
		#[DB\Ignore] public int $pointsIndividual=0,
		?int $joined=null,
	) {
		$this->joined = $joined ?? time();
	}
}
