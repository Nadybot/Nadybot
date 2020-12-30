<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class BossNamedb extends DBRow {
	/** The internal ID of this database entry */
	public int $bossid;

	/** Full name of this boss */
	public string $bossname;
}