<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

use Nadybot\Core\DBRow;

class Skill extends DBRow {
	public int $id;
	public string $name;
}
