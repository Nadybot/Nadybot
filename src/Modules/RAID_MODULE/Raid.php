<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Raid extends DBRow {
	/** The internal ID of this raid */
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
	#[NCA\DB\Ignore]
	public int $last_award_from_ticker = 0;

	/**
	 * At what frequency to announce the running raid
	 * Set to 0 to turn this off, otherwise the number of seconds
	 */
	public int $announce_interval = 0;

	/** UNIX timestamp when the raid was announced the last time */
	#[NCA\DB\Ignore]
	public int $last_announcement = 0;

	/** Is the raid currently locked and joining is forbidden? */
	public bool $locked = false;

	/** UNIX timestamp when this raid/raid part was started */
	public int $started;

	/** Name of the raidleader who started the raid */
	public string $started_by;

	/** UNIX timestamp when this raid was stopped */
	public ?int $stopped = null;

	/** Name of the raidleader who stopped the raid */
	public ?string $stopped_by = null;

	/**
	 * Maximum number of allowed characters in the raid
	 * If 0 or NULL, this is not limited
	 */
	public ?int $max_members = null;

	/** If set, then no points will be awarded until resumed */
	public bool $ticker_paused = false;

	/**
	 * List of all players who are or were in the raid
	 *
	 * @var array<string,RaidMember>
	 */
	#[NCA\DB\Ignore]
	public array $raiders = [];

	/**
	 * Internal array to track which mains already received points
	 *
	 * @var array<string,bool>
	 */
	#[NCA\DB\Ignore]
	public array $pointsGiven = [];

	#[NCA\DB\Ignore]
	public bool $we_are_most_recent_message = false;

	public function __construct() {
		$this->started = time();
		$this->last_announcement = time();
		$this->last_award_from_ticker = time();
	}

	public function numActiveRaiders(): int {
		$numRaiders = 0;
		foreach ($this->raiders as $name => $raider) {
			if (isset($raider->left)) {
				continue;
			}
			$numRaiders++;
		}
		return $numRaiders;
	}

	public function getAnnounceMessage(?string $joinMessage=null): string {
		$numRaiders = $this->numActiveRaiders();
		$countMsg = "";
		if ($this->max_members > 0) {
			$countMsg = " ({$numRaiders}/{$this->max_members} slots)";
		}
		$msg = "Raid is running: <highlight>{$this->description}<end>{$countMsg} :: ";
		if ($this->locked) {
			$msg .= "<off>raid is locked<end>";
		} elseif ($this->max_members > 0 && $this->max_members <= $numRaiders) {
			$msg .= "<off>raid is full<end>{$countMsg}";
		} elseif ($joinMessage !== null) {
			$msg .= $joinMessage;
		}
		return $msg;
	}
}
