<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CmdAlias extends DBRow {
	public string $cmd;
	public ?string $module = null;
	public string $alias;
	public int $status = 0;
}
