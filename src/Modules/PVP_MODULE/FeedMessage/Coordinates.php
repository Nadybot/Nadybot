<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

use Nadybot\Core\StringableTrait;

class Coordinates {
	use StringableTrait;

	public function __construct(
		public int $x,
		public int $y,
	) {
	}
}
