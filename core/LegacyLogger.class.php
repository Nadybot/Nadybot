<?php

namespace Budabot\Core;

use Logger;
use LoggerLevel;

/**
 * A compatibility layer for logging
 */
class LegacyLogger {
	/**
	 * Log a message according to log settings
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @param string $tag The log tag (e.g. Core, Registry, StartUp, etc.)
	 * @param mixed $message The message to log
	 * @return void
	 */
	public static function log($category, $tag, $message) {
		$logger = Logger::getLogger($tag);
		$level = LegacyLogger::getLoggerLevel($category);
		$logger->log($level, $message);
	}

	/**
	 * Get the log4php log level for a Budabot logging category
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @return \LoggerLevel The log4php log level
	 */
	public static function getLoggerLevel($category) {
		$level = LoggerLevel::getLevelOff();
		switch (strtolower($category)) {
			case 'trace':
				$level = LoggerLevel::getLevelTrace();
				break;
			case 'debug':
				$level = LoggerLevel::getLevelDebug();
				break;
			case 'warn':
				$level = LoggerLevel::getLevelWarn();
				break;
			case 'error':
				$level = LoggerLevel::getLevelError();
				break;
			case 'fatal':
				$level = LoggerLevel::getLevelFatal();
				break;

			case 'info':
			default:
				$level = LoggerLevel::getLevelInfo();
				break;
		}
		return $level;
	}
}
