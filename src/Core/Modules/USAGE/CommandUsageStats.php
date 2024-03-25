<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

use Nadybot\Core\DBRow;

class CommandUsageStats extends DBRow {
	public function __construct(
		public string $command,
		public int $count,
	) {
	}
}
