<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/**
 * This interface allows to on-the-fly modify log messages.
 * You can change their log level, the message being logged and/or the context.
 * This is mainly used to prefix all log-messages from a module.
 */
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
