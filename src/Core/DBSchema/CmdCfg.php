<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CmdCfg extends DBRow {
	public ?string $module;
	public ?string $cmdevent;
	public ?string $type;
	public ?string $file;
	public ?string $cmd;
	public ?string $admin;
	public ?string $description='none';
	public ?int $verify=0;
	public ?int $status=0;
	public ?string $dependson='none';
	public ?string $help;
}
