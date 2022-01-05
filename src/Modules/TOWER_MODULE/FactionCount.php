<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Nadybot\Core\DBRow;

class FactionCount extends DBRow {
	public string $faction;
	public int $num;
}
