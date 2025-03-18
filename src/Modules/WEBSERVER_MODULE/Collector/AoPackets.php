<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use AO\Package\PackageType;
use Nadybot\Core\{
	Attributes as NCA,
	Nadybot,
};
use Nadybot\Modules\WEBSERVER_MODULE\Dataset;

class AoPackets extends Dataset {
	#[NCA\Inject]
	private Nadybot $chatBot;

	public function getValues(): array {
		$lines = ['# TYPE ao_packets counter'];
		foreach ($this->chatBot->aoClient->getStatistics()->packagesRead as $type => $count) {
			$lines []= 'ao_packets{direction="in",type="'.
				(PackageType::tryFrom($type)->name ?? (string)$type) . "\"} {$count}";
		}
		foreach ($this->chatBot->aoClient->getStatistics()->packagesWritten as $type => $count) {
			$lines []= 'ao_packets{direction="out",type="'.
				(PackageType::tryFrom($type)->name ?? (string)$type) . "\"} {$count}";
		}
		return $lines;
	}
}
