<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

use Nadybot\Core\DBRow;

class PlayerUsageStats extends DBRow {
	public function __construct(
		public string $sender,
		public int $count,
	) {
	}
}
