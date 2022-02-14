<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\GaugeProvider;

class MemoryPeakUsage implements GaugeProvider {
	public function getValue(): float {
		return memory_get_peak_usage(true);
	}

	public function getTags(): array {
		return ["type" => "peak"];
	}
}
