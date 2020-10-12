<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class Raid extends DBRow {
	/**
	 * The internal ID of this raid
	 */
	public int $raid_id;

	/** The description of the raid */
	public string $description;

	/**
	 * At what frequency do raiders get points just for being in the raid?
	 * Set to 0 to turn this off, otherwise the number of seconds
	 */
	public int $seconds_per_point = 0;

	/**
	 * UNIX timestamp of the last time the raid force received
	 * raid points for participation
	 */
	public int $last_award_from_ticker = 0;

	/**
	 * At what frequency to announce the running raid
	 * Set to 0 to turn this off, otherwise the number of seconds
	 */
	public int $announce_interval = 0;

	/**
	 * UNIX timestamp when the raid was announced the last time
	 */
	public int $last_announcement = 0;

	/**
	 * Is the raid currently locked and joining as forbidden?
	 */
	public bool $locked = false;

	/**
	 * UNIX timestamp when this raid/raid part was started
	 */
	public int $started;

	/**
	 * Name of the raidleader who started the raid
	 */
	public string $started_by;

	/**
	 * UNIX timestamp when this raid was stopped
	 */
	public ?int $stopped = null;

	/**
	 * Name of the raidleader who stopped the raid
	 */
	public ?string $stopped_by;

	/**
	 * List of all players who are or were in the raid
	 * @var array<string,RaidMember>
	 */
	public array $raiders = [];

	/**
	 * Internal array to track which mains already received points
	 */
	public array $pointsGiven = [];

	public bool $we_are_most_recent_message = false;

	public function __construct() {
		$this->started = time();
		$this->last_announcement = time();
		$this->last_award_from_ticker = time();
	}
	
	public function getAnnounceMessage(?string $joinMessage=null): string {
		$msg = "Raid is running: <highlight>{$this->description}<end> :: ";
		if ($this->locked) {
			$msg .= "<red>raid is locked<end>.";
		} elseif ($joinMessage !== null) {
			$msg .= $joinMessage;
		}
		return $msg;
	}
}
