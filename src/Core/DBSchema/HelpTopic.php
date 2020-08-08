<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class HelpTopic extends DBRow {
	public string $file;
	public int $sort;
	public string $admin_list;
	public string $module;
	public string $name;
	public string $description;
}
