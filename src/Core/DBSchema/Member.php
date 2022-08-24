<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Member extends DBRow {
	public string $name;
	public int $autoinv = 0;
	public int $joined = 0;
	public ?string $added_by = null;
}
