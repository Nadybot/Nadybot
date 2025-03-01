<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;

/** This represents the nick name by which a character is known */
#[Table(name: 'nickname', shared: Shared::Yes)]
class Nickname extends DBTable {
	/**
	 * @param string $main Main character
	 * @param string $nick Nick name of this main and all their alts
	 */
	public function __construct(
		#[PK] public string $main,
		public string $nick,
	) {
	}
}
