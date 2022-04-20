<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	BuddylistEntry,
	BuddylistManager,
	ConfigFile,
};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class OnlineOrgStats implements GaugeProvider {
	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public ConfigFile $config;

	public function getValue(): float {
		return count(array_filter(
			$this->buddylistManager->buddyList,
			function (BuddylistEntry $entry): bool {
				return $entry->online && $entry->hasType('org');
			}
		));
	}

	public function getTags(): array {
		return ["type" => "org"];
	}
}
