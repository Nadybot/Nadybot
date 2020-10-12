<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\DBRow;

class RaidLog extends DBRow {
	/** The ID of the raid to which this belongs */
	public int $raid_id;

	/** The raid description of the raid */
	public ?string $description;

	/** How many seconds for 1 raid point or 0 if disabled */
	public int $seconds_per_point;

	/** At which interval was the raid announced? 0 meansoff */
	public int $announce_interval;

	/** Was the raid locked? */
	public bool $locked;

	/** At which time did the change occur? */
	public int $time;
}
