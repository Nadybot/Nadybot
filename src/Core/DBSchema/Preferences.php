<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes\DB, DBTable};

/** This table is used to store custom character preferences */
#[DB\Table(name: 'preferences')]
class Preferences extends DBTable {
	/**
	 * @param string $sender Name of the character this preference belongs to
	 * @param string $name   Name of the preference
	 * @param string $value  Value of the preference
	 */
	public function __construct(
		#[DB\PK] public string $sender,
		#[DB\PK] public string $name,
		public string $value,
	) {
	}
}
