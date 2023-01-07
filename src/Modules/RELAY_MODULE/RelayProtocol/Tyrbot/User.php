<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class User {
	public function __construct(
		public ?int $id,
		public string $name,
	) {
	}
}
