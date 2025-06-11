<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Table};
use Nadybot\Core\DBTable;
use Ramsey\Uuid\{Uuid, UuidInterface};

#[Table(name: 'tracking')]
class Tracking extends DBTable {
	#[PK] public UuidInterface $id;

	/**
	 * @param int            $uid   The UID of the character being tracker
	 * @param int            $dt    The UNIX timestamp of the event
	 * @param string         $event What happened
	 * @param ?UuidInterface $id    ID of this tracking entry (if known)
	 */
	public function __construct(
		public int $uid,
		public int $dt,
		public string $event,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
