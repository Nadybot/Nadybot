<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Modules\WEBSERVER_MODULE\Dataset;

class RaidMemberStats extends Dataset {
	#[NCA\Inject]
	private RaidController $raidController;

	public function getValues(): array {
		$numActive = 0;
		$numInactive = 0;
		$raid = $this->raidController->raid;
		if (isset($raid)) {
			foreach ($raid->raiders as $name => $raider) {
				if (isset($raider->left)) {
					$numInactive++;
				} else {
					$numActive++;
				}
			}
		}
		return [
			"# TYPE raid gauge",
			"raid{type=\"inactive\"} {$numInactive}",
			"raid{type=\"active\"} {$numActive}",
		];
	}
}
