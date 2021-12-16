<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class ImplantBonusStats {
	public string $slot = 'Faded';

	public int $buff;

	/** @var int[] */
	public array $range;

	public function __construct(int $slot) {
		if ($slot === ImplantController::FADED) {
			$this->slot = 'Faded';
		} elseif ($slot === ImplantController::BRIGHT) {
			$this->slot = 'Bright';
		} else {
			$this->slot = 'Shiny';
		}
	}
}
