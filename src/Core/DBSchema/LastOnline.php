<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class LastOnline extends DBRow {
	/**
	 * @param int    $uid  uid of the character
	 * @param string $name name of the character
	 * @param int    $dt   Timestamp when $name was last online
	 */
	public function __construct(
		public int $uid,
		public string $name,
		public int $dt,
		#[NCA\DB\Ignore] public string $main,
	) {
	}
}
