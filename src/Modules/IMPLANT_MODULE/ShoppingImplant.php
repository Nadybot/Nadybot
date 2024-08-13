<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class ShoppingImplant {
	/** psalm-param int<1,300> $ql */
	public function __construct(
		public int $ql,
		public string $slot,
	) {
	}
}
