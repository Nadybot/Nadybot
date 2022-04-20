<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Collector;

use Nadybot\Core\{
	AOChatPacket,
	Attributes as NCA,
	Nadybot,
};
use Nadybot\Modules\WEBSERVER_MODULE\Dataset;
use ReflectionClass;
use ReflectionClassConstant;

class AoPackets extends Dataset {
	#[NCA\Inject]
	public Nadybot $chatBot;

	public function getValues(): array {
		$ref = new ReflectionClass(AOChatPacket::class);
		$lookup = array_flip($ref->getConstants(ReflectionClassConstant::IS_PUBLIC));
		$lines = ["# TYPE ao_packets counter"];
		foreach ($this->chatBot->packetsIn as $type => $count) {
			$lines []= 'ao_packets{direction="in",type="'.
				($lookup[$type] ?? $type) . "\"} {$count}";
		}
		foreach ($this->chatBot->packetsOut as $type => $count) {
			$lines []= 'ao_packets{direction="out",type="'.
				($lookup[$type] ?? $type) . "\"} {$count}";
		}
		return $lines;
	}
}
