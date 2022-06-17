<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Core\{Attributes as NCA, Nadybot};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class BuddylistSize implements GaugeProvider {
	#[NCA\Inject]
	public Nadybot $chatBot;

	public function getValue(): float {
		return $this->chatBot->getBuddyListSize();
	}

	public function getTags(): array {
		return ["type" => "size"];
	}
}
