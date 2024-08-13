<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class ShoppingCluster {
	/** psalm-param int<0,300> $ql */
	public function __construct(
		public int $ql,
		public string $slot,
		public string $grade,
		public string $name,
	) {
	}
}
