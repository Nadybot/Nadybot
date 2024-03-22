<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class MemoryUsage implements GaugeProvider {
	public function getValue(): float {
		return memory_get_usage();
	}

	public function getTags(): array {
		return ['type' => 'used'];
	}
}
