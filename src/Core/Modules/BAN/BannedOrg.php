<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\{Attributes as NCA, DBRow};

class BannedOrg extends DBRow {
	/** The ID of the org that is or was banned */
	public int $org_id;

	/** The name of the org that is or was banned */
	#[NCA\DB\Ignore]
	public string $org_name;

	/** Name of the person banning the org */
	public string $banned_by;

	/** UNIX timestamp when the ban starts */
	public int $start;

	/**
	 * If this is a temporary ban, this is the UNIX timestamp
	 * when the ban will end
	 */
	public ?int $end;

	/** Reason why the org was banned */
	public string $reason;
}
