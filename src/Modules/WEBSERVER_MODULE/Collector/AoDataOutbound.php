<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Nadybot;
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\CounterProvider;

class AoDataOutbound implements CounterProvider {
	#[NCA\Inject]
	public Nadybot $chatBot;

	public function getValue(): int {
		return $this->chatBot->numBytesOut;
	}

	public function getTags(): array {
		return ["direction" => "out"];
	}
}
