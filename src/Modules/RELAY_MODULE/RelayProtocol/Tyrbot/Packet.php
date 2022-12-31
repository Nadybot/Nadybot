<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class Packet {
	public function __construct(
		public string $type,
	) {
	}
}
