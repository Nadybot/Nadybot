<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\USAGE;

use Nadybot\Core\DBRow;

class ChannelUsageStats extends DBRow {
	public function __construct(
		public string $channel,
		public int $count,
	) {
	}
}
