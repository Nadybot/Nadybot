<?php declare(strict_types=1);

namespace Nadybot\Core;

class MonologConfig {
	/**
	 * @param array<string,MonologFormatter> $formatters
	 * @param array<string,MonologHandler>   $handlers
	 * @param array<string,string>           $channels
	 */
	public function __construct(
		public array $formatters,
		public array $handlers,
		public array $channels,
	) {
	}
}
