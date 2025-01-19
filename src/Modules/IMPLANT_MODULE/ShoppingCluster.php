<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\ImplantSlot;

class ShoppingCluster {
	/** psalm-param int<0,300> $ql */
	public function __construct(
		public int $ql,
		public ImplantSlot $slot,
		public ClusterGrade $grade,
		public string $name,
	) {
	}
}
