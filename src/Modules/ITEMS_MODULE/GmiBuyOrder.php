<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class GmiBuyOrder {
	public function __construct(
		public int $price,
		public int $minQl,
		public int $maxQl,
		public int $count,
		public string $buyer,
		public int $expiration,
	) {
	}
}
