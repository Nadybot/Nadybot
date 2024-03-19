<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\StringableTrait;

class GasUpdate {
	use StringableTrait;

	public function __construct(
		public int $playfield_id,
		public int $site_id,
		public int $gas,
	) {
	}
}
