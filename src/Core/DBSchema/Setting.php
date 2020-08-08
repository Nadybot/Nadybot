<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Setting extends DBRow {
	public string $name;
	public ?string $module;
	public ?string $type;
	public string $mode;
	public ?string $value="0";
	public ?string $options="0";
	public ?string $intoptions="0";
	public ?string $description;
	public ?string $source;
	public ?string $admin;
	public ?int $verify=0;
	public ?string $help;
}
