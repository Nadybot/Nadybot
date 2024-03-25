<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class HelpTopic extends DBRow {
	public function __construct(
		public int $sort,
		public string $admin_list,
		public string $module,
		public string $name,
		public string $description,
		public ?string $file=null,
	) {
	}
}
