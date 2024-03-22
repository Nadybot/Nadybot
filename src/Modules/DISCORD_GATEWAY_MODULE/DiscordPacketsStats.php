<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Modules\WEBSERVER_MODULE\Interfaces\CounterProvider;

class DiscordPacketsStats implements CounterProvider {
	private int $counter = 0;

	public function __construct(
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
		return ['direction' => $this->direction];
	}
}
