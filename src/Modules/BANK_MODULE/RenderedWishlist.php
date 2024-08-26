<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

class RenderedWishlist {
	public function __construct(
		public string $blob,
		public int $numItems,
	) {
	}
}
