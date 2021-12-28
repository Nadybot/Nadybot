<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Nadybot\Core\DBRow;

class Nanoline extends DBRow {
	public int $strain_id;
	public string $name;
}
