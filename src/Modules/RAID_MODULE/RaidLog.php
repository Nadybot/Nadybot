<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes\DB\Table;
use Nadybot\Core\DBTable;
use Ramsey\Uuid\UuidInterface;

#[Table(name: 'raid_log')]
class RaidLog extends DBTable {
	/**
	 * @param UuidInterface $raid_id           The ID of the raid to which this belongs
	 * @param ?string       $description       The raid description of the raid
	 * @param int           $seconds_per_point How many seconds for 1 raid point or 0 if disabled
	 * @param int           $announce_interval At which interval was the raid announced? 0 meansoff
	 * @param bool          $locked            Was the raid locked?
	 * @param int           $time              At which time did the change occur?
	 * @param ?int          $max_members       Maximum number of allowed characters in the raid If 0 or NULL, this is not limited
	 * @param bool          $ticker_paused     If set, then no points will be awarded until resumed
	 */
	public function __construct(
		public UuidInterface $raid_id,
		public ?string $description,
		public int $seconds_per_point,
		public int $announce_interval,
		public bool $locked,
		public int $time,
		public ?int $max_members=null,
		public bool $ticker_paused=false,
	) {
	}
}
