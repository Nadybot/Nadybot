<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class GmiSellOrder {
	public function __construct(
		public int $price,
		public int $ql,
		public int $count,
		public string $seller,
		public int $expiration,
	) {
	}
}
