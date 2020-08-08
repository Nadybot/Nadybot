<?php declare(strict_types=1);

namespace Nadybot\Core;

use Logger;
use LoggerLevel;

/**
 * A compatibility layer for logging
 */
class LegacyLogger {
	/**
	 * Log a message according to log settings
	 */
	public static function log(string $category, string $tag, $message): void {
		$logger = Logger::getLogger($tag);
		$level = LegacyLogger::getLoggerLevel($category);
		$logger->log($level, $message);
	}

	/**
	 * Get the log4php log level for a Nadybot logging category
	 */
	public static function getLoggerLevel(string $category): LoggerLevel {
		switch (strtolower($category)) {
			case 'trace':
				return LoggerLevel::getLevelTrace();
			case 'debug':
				return  LoggerLevel::getLevelDebug();
			case 'warn':
				return LoggerLevel::getLevelWarn();
			case 'error':
				return LoggerLevel::getLevelError();
			case 'fatal':
				return LoggerLevel::getLevelFatal();

			case 'info':
			default:
				return LoggerLevel::getLevelInfo();
		}
		return LoggerLevel::getLevelOff();
	}
}
