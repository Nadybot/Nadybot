<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\{PK, Table};
use Nadybot\Core\DBTable;

/** The admin table keeps track who has which mod/admin-rank */
#[Table(name: 'admin')]
class Admin extends DBTable {
	/**
	 * @param string   $name       Name of the character
	 * @param null|int $adminlevel The admin level of this character
	 *                             * 3: admin
	 *                             * 4: mod
	 */
	public function __construct(
		#[PK] public string $name,
		public ?int $adminlevel=0,
	) {
	}
}
