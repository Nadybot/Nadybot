<?php declare(strict_types=1);

namespace Nadybot\Modules\ONLINE_MODULE;

use Nadybot\Core\{Attributes as NCA, Nadybot};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class OnlinePrivStats implements GaugeProvider {
	#[NCA\Inject]
	private Nadybot $chatBot;

	public function getValue(): float {
		return count($this->chatBot->chatlist);
	}

	public function getTags(): array {
		return ['type' => 'priv'];
	}
}
