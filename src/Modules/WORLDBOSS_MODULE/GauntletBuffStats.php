<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class GauntletBuffStats implements GaugeProvider {
	public function __construct(
		private GauntletBuffController $gauBuff,
		private string $faction
	) {
	}

	public function getValue(): float {
		return $this->gauBuff->getIsActive($this->faction) ? 1 : 0;
	}

	public function getTags(): array {
		return ["type" => "gaubuff-{$this->faction}"];
	}
}
