<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class LastOnline extends DBRow {
	/** uid of the character */
	public int $uid;

	/** name of the character */
	public string $name;

	/** Timestamp when $name was last online */
	public int $dt;

	#[NCA\DB\Ignore]
	public string $main;
}
