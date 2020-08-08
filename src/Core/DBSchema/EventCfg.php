<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class EventCfg extends DBRow {
	public ?string $module;
	public ?string $type;
	public ?string $file;
	public ?string $description;
	public ?int $verify=0;
	public ?int $status=0;
	public ?string $help;
}
