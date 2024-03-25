<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\DBRow;

class Online extends DBRow {
	public function __construct(
		public string $name,
		public string $channel,
		public string $channel_type,
		public string $added_by,
		public int $dt,
		public ?string $afk='',
	) {
	}
}
