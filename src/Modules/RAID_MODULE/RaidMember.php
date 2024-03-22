<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class RaidMember extends DBRow {
	/** UNIX Timestamp when they joined the raid */
	public int $joined;

	/**
	 * @param int    $raid_id          ID of the raid this player represents
	 * @param string $player           Name of the character
	 * @param ?int   $left             UNIX Timestamp when they left the raid/were kicked, null if still in
	 * @param int    $points           How many points have they gotten in this raid
	 * @param int    $pointsRewarded   How many points have they received from rewards in this raid
	 * @param int    $pointsIndividual How many points have they gained/lost individually in this raid
	 * @param ?int   $joined           UNIX Timestamp when they joined the raid
	 */
	public function __construct(
		public int $raid_id,
		public string $player,
		public ?int $left=null,
		#[NCA\DB\Ignore] public int $points=0,
		#[NCA\DB\Ignore] public int $pointsRewarded=0,
		#[NCA\DB\Ignore] public int $pointsIndividual=0,
		?int $joined=null,
	) {
		$this->joined = $joined ?? time();
	}
}
