<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class RaidStateStats implements GaugeProvider {
	#[NCA\Inject]
	private RaidController $raidController;

	public function getValue(): float {
		return isset($this->raidController->raid) ? 1 : 0;
	}

	public function getTags(): array {
		return ['type' => 'raid'];
	}
}
