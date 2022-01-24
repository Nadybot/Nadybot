<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CmdPermSetMapping extends DBRow {
	public ?int $id = null;
	public string $name;
	public string $source;
	public string $symbol;
	public bool $symbol_optional = false;
	public bool $feedback = true;
}
