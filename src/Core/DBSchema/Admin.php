<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Admin extends DBRow {
	public string $name;
	public ?int $adminlevel = 0;
}
