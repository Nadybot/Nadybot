<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\Shared;
use Nadybot\Core\{Attributes as NCA, DBTable};

/** This table tracks who was last online when */
#[NCA\DB\Table(name: 'last_online', shared: Shared::Yes)]
class LastOnline extends DBTable {
	/**
	 * @param int     $uid  UID of the character
	 * @param string  $name Name of the character
	 * @param int     $dt   Timestamp when $name was last online
	 * @param ?string $main Name of the main character
	 */
	public function __construct(
		#[NCA\DB\PK] public int $uid,
		public string $name,
		public int $dt,
		#[NCA\DB\Ignore] public ?string $main=null,
	) {
	}
}
