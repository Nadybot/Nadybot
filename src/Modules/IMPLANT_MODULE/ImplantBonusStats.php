<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\MinMax;

class ImplantBonusStats {
	public function __construct(
		public int $buff,
		public MinMax $range,
		public ClusterGrade $slot,
	) {
	}
}
