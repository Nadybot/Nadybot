<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\CounterProvider;

class RelayPacketsStats implements CounterProvider {
	private int $counter = 0;

	public function __construct(
		private string $relayType,
		private string $relayName,
		private string $direction,
	) {
	}

	public function getValue(): int {
		return $this->counter;
	}

	public function inc(int $amount=1): void {
		$this->counter += max(1, $amount);
	}

	public function getTags(): array {
		return ["type" => $this->relayType, "name" => $this->relayName, "direction" => $this->direction];
	}
}
