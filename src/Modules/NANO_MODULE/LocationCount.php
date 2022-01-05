<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Nadybot\Core\DBRow;

class LocationCount extends DBRow {
	public string $location;
	public int $count;
}
