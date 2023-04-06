<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Modules\PVP_MODULE\FeedMessage\TowerAttack;

class GasOverrides {
	/** @var TowerAttack[] */
	public array $attacks = [];

	public function reset(): void {
		$this->attacks = [];
	}

	public function registerAttack(TowerAttack $attack): void {
		$this->attacks []= $attack;
	}
}
