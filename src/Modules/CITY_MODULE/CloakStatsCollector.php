<?php declare(strict_types=1);

namespace Nadybot\Modules\CITY_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class CloakStatsCollector implements GaugeProvider {
	#[NCA\Inject]
	private CloakController $cloakController;

	public function getValue(): float {
		$entry = $this->cloakController->getLastOrgEntry();
		return isset($entry) ? ($entry->action === "on" ? 1 : 0) : -1;
	}

	public function getTags(): array {
		return ["type" => "cloak"];
	}
}
