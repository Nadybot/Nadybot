<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\FeedMessage;

class Coordinates {
	public function __construct(
		public int $x,
		public int $y,
	) {
	}
}
