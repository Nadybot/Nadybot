<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class GasUpdate {
	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public int $gas,
	) {
	}
}
