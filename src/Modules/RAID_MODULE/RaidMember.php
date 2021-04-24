<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidMember extends DBRow {
	/**
	 * ID of the raid this player represents
	 */
	public int $raid_id;

	/**
	 * Name of the character
	 */
	public string $player;

	/** UNIX Timestamp when they joined the raid */
	public int $joined;

	/** UNIX Timestamp when they left the raid/were kicked, null if still in */
	public ?int $left = null;

	/**
	 * How many points have they gotten in this raid
	 * @db:ignore
	 */
	public int $points = 0;

	/**
	 * How many points have they received from rewards in this raid
	 * @db:ignore
	 */
	public int $pointsRewarded = 0;

	/**
	 * How many points have they gained/lost individually in this raid
	 * @db:ignore
	 */
	public int $pointsIndividual = 0;

	public function __construct() {
		$this->joined = time();
	}
}
