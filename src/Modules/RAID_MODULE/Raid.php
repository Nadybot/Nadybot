<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'raid')]
class Raid extends DBTable {
	/** UNIX timestamp when this raid/raid part was started */
	public int $started;

	/** UNIX timestamp when the raid was announced the last time */
	#[NCA\DB\Ignore] public int $last_announcement;

	/**
	 * UNIX timestamp of the last time the raid force received
	 * raid points for participation
	 */
	#[NCA\DB\Ignore] public int $last_award_from_ticker;

	/** The internal ID of this raid */
	#[NCA\DB\PK] public UuidInterface $raid_id;

	/**
	 * @param string                   $description            The description of the raid
	 * @param int                      $seconds_per_point      At what frequency do raiders get points just for being in the raid?
	 *                                                         Set to 0 to turn this off, otherwise the number of seconds
	 * @param int                      $announce_interval      At what frequency to announce the running raid
	 *                                                         Set to 0 to turn this off, otherwise the number of seconds
	 * @param string                   $started_by             Name of the raidleader who started the raid
	 * @param bool                     $locked                 Is the raid currently locked and joining is forbidden?
	 * @param ?int                     $started                UNIX timestamp when this raid/raid part was started
	 * @param ?int                     $last_announcement      UNIX timestamp when the raid was announced the last time
	 * @param ?int                     $last_award_from_ticker UNIX timestamp of the last time the raid force received
	 *                                                         raid points for participation
	 * @param ?int                     $stopped                UNIX timestamp when this raid was stopped
	 * @param ?string                  $stopped_by             Name of the raidleader who stopped the raid
	 * @param ?int                     $max_members            Maximum number of allowed characters in the raid
	 *                                                         If 0 or NULL, this is not limited
	 * @param bool                     $ticker_paused          If set, then no points will be awarded until resumed
	 * @param ?UuidInterface           $raid_id                The internal ID of this raid
	 * @param array<string,RaidMember> $raiders                List of all players who are or were in the raid
	 * @param array<string,bool>       $pointsGiven            Internal array to track which mains already received points
	 */
	public function __construct(
		public string $description,
		public int $seconds_per_point,
		public int $announce_interval,
		public string $started_by,
		public bool $locked=false,
		?int $started=null,
		?int $last_announcement=null,
		?int $last_award_from_ticker=null,
		public ?int $stopped=null,
		public ?string $stopped_by=null,
		public ?int $max_members=null,
		public bool $ticker_paused=false,
		?UuidInterface $raid_id=null,
		#[NCA\DB\Ignore] public array $raiders=[],
		#[NCA\DB\Ignore] public array $pointsGiven=[],
		#[NCA\DB\Ignore] public bool $we_are_most_recent_message=false,
	) {
		$this->started = $started ?? time();
		$this->last_announcement = $last_announcement ?? time();
		$this->last_award_from_ticker = $last_award_from_ticker ?? time();
		$dt = null;
		if (isset($started) && !isset($raid_id)) {
			$dt = (new DateTimeImmutable())->setTimestamp($started);
		}
		$this->raid_id = $raid_id ?? Uuid::uuid7($dt);
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
		$countMsg = '';
		if (isset($this->max_members) && $this->max_members > 0) {
			$countMsg = " ({$numRaiders}/{$this->max_members} slots)";
		}
		$msg = "Raid is running: <highlight>{$this->description}<end>{$countMsg} :: ";
		if ($this->locked) {
			$msg .= '<off>raid is locked<end>';
		} elseif (isset($this->max_members) && $this->max_members > 0 && $this->max_members <= $numRaiders) {
			$msg .= "<off>raid is full<end>{$countMsg}";
		} elseif ($joinMessage !== null) {
			$msg .= $joinMessage;
		}
		return $msg;
	}
}
