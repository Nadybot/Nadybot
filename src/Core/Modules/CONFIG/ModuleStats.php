<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBRow;

class ModuleStats extends DBRow {
	public string $module;
	public int $count_cmd_disabled;
	public int $count_cmd_enabled;
	public int $count_events_disabled;
	public int $count_events_enabled;
	public int $count_settings;
}
