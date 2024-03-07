<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use AO\Package;
use Nadybot\Core\{
	Attributes as NCA,
	Nadybot,
};
use Nadybot\Modules\WEBSERVER_MODULE\Dataset;

class AoPackets extends Dataset {
	#[NCA\Inject]
	public Nadybot $chatBot;

	public function getValues(): array {
		$lines = ["# TYPE ao_packets counter"];
		foreach ($this->chatBot->aoClient->getStatistics()->packagesRead as $type => $count) {
			$lines []= 'ao_packets{direction="in",type="'.
				(Package\Type::tryFrom($type)?->name ?? $type) . "\"} {$count}";
		}
		foreach ($this->chatBot->aoClient->getStatistics()->packagesWritten as $type => $count) {
			$lines []= 'ao_packets{direction="out",type="'.
				(Package\Type::tryFrom($type)?->name ?? $type) . "\"} {$count}";
		}
		return $lines;
	}
}
