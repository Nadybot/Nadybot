<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

/** This is a list of characters that should have no rate limit for messaging us */
#[Table(name: 'rateignorelist', shared: Shared::Yes)]
class RateIgnoreList extends DBTable {
	/**
	 * @param string $name     Character name without rate limit
	 * @param string $added_by Name of the character who added us to this list
	 * @param int    $added_dt Unix time stamp when `$name` was added to this list
	 */
	public function __construct(
		#[PK] public string $name,
		public string $added_by,
		public int $added_dt,
	) {
	}
}
