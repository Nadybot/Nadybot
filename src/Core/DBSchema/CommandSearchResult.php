<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CommandSearchResult extends DBRow {
	public string $module;
	public string $cmd;
	public ?string $help;
	public string $description;
	public string $admin;
}
