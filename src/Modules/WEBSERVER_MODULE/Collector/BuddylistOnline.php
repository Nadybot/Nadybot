<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Core\{Attributes as NCA, BuddylistManager};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class BuddylistOnline implements GaugeProvider {
	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	public function getValue(): float {
		return count($this->buddylistManager->getOnline());
	}

	public function getTags(): array {
		return ['type' => 'online'];
	}
}
