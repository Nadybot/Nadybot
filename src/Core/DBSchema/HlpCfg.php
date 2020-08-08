<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class HlpCfg extends DBRow {
	public string $name;
	public ?string $module;
	public ?string $file;
	public ?string $description;
	public ?string $admin;
	public int $verify = 0;
}
