<?php declare(strict_types=1);

namespace Nadybot\Core;

interface LogWrapInterface {
	/**
	 * Wrap the logger by modifying all logging parameters
	 *
	 * @param 100|200|250|300|400|500|550|600 $logLevel
	 * @param array<string,mixed>             $context
	 *
	 * @return array{100|200|250|300|400|500|550|600, string, array<string, mixed>}
	 */
	public function wrapLogs(int $logLevel, string $message, array $context): array;
}
