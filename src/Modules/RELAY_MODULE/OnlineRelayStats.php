<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{Attributes as NCA, ConfigFile};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class OnlineRelayStats implements GaugeProvider {
	#[NCA\Inject]
	public RelayController $relayController;

	#[NCA\Inject]
	public ConfigFile $config;

	public function getValue(): float {
		$sum = 0;
		foreach ($this->relayController->relays as $relay) {
			foreach ($relay->getOnlineList() as $type => $online) {
				$sum += count($online);
			}
		}
		return $sum;
	}

	public function getTags(): array {
		return ["type" => "relay"];
	}
}
