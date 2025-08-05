<?php declare(strict_types=1);

namespace Nadybot\Core;

class MonologFormatter {
	/**
	 * @param array<string,mixed>               $options
	 * @param array<string,array<string,mixed>> $calls
	 */
	public function __construct(
		public string $type,
		public array $options,
		public array $calls=[],
	) {
	}
}
