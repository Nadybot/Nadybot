<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\MinMax;

class ImplantBonusStats {
	public string $slot = 'Faded';

	public function __construct(
		public int $buff,
		public MinMax $range,
		int|string $slot,
	) {
		if (is_string($slot)) {
			$this->slot = $slot;
		} elseif ($slot === ImplantController::FADED) {
			$this->slot = 'Faded';
		} elseif ($slot === ImplantController::BRIGHT) {
			$this->slot = 'Bright';
		} else {
			$this->slot = 'Shiny';
		}
	}
}
