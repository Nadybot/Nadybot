<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class AODBEntry extends DBRow {
	public int $lowid;
	public int $highid;
	public int $lowql;
	public int $highql;
	public string $name;
	public int $icon;
}
