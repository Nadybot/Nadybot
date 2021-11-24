<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

class ImplantBonusStats {
	/** @var string */
	public $slot = 'Faded';

	/** @var int */
	public $buff;

	/** @var int[] */
	public $range;

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
