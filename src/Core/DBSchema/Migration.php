<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use DateTime;
use Nadybot\Core\DBRow;

class Migration extends DBRow {
	public int $id;
	public string $module;
	public string $migration;
	public DateTime $applied_at;
}
