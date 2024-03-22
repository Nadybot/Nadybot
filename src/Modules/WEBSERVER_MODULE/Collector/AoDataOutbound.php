<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Core\{Attributes as NCA, Nadybot};
use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\CounterProvider;

class AoDataOutbound implements CounterProvider {
	#[NCA\Inject]
	private Nadybot $chatBot;

	public function getValue(): int {
		return $this->chatBot->aoClient->getStatistics()->bytesWritten;
	}

	public function getTags(): array {
		return ['direction' => 'out'];
	}
}
