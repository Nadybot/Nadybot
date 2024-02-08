<?php declare(strict_types=1);

namespace Nadybot\Core;

interface LogWrapInterface {
	/**
	 * Wrap the logger by modifying all logging parameters
	 *
	 * @param integer $logLevel
	 * @param string $message
	 * @param array $context
	 * @return array{int, string, array}
	 */
	public function wrapLogs(int $logLevel, string $message, array $context): array;
}
