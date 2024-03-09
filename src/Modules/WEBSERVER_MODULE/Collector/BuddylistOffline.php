<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Core\{Attributes as NCA, BuddylistManager};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class BuddylistOffline implements GaugeProvider {
	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	public function getValue(): float {
		return $this->buddylistManager->getUsedBuddySlots()
			- count($this->buddylistManager->getOnline());
	}

	public function getTags(): array {
		return ["type" => "offline"];
	}
}
