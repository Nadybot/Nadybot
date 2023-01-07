<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class Source {
	public function __construct(
		public string $name,
		public ?string $label,
		public ?string $channel,
		public string $type,
		public int $server,
	) {
	}
}
