<?php declare(strict_types=1);

namespace Nadybot\Core;

use Logger;
use ReflectionProperty;
use Throwable;

/**
 * A wrapper class to log4php
 *
 * @Instance("logger")
 */
class LoggerWrapper {
	/**
	 * The actual log4php logger
	 */
	private Logger $logger;

	/**
	 * The actual log4php logger for tag CHAT
	 */
	private Logger $chatLogger;

	public function __construct(string $tag) {
		$this->logger = Logger::getLogger($tag);
		$this->chatLogger = Logger::getLogger('CHAT');
		Registry::setInstance("logger", $this);
	}

	/**
	 * Log a message according to log settings
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @param mixed $message The message to log
	 * @param Throwable $throwable Optional throwable information to include in the logging event
	 * @return void
	 */
	public function log(string $category, string $message, ?Throwable $throwable=null): void {
		$level = LegacyLogger::getLoggerLevel($category);
		if (isset($throwable)) {
			$extraInfo = "";
			if (strpos($message, " in file ") === false) {
				$extraInfo .= " in file " . ($throwable->getFile() ?? "Unknown") . ":".
					($throwable->getLine() ?? "Unknown");
			}
			if (!preg_match("/^#\d+ /m", $message)) {
				$extraInfo .= PHP_EOL . $throwable->getTraceAsString();
			}
			$extraInfo = str_replace(dirname(__DIR__, 2) . "/", "", $extraInfo);
			$message .= $extraInfo;
		}
		$this->logger->log($level, $message, $throwable);
	}

	/**
	 * Log a chat message, stripping potential HTML code from it
	 *
	 * @param string $channel Either "Buddy" or an org or private-channel name
	 * @param string|int $sender The name of the sender, or a number representing the channel
	 * @param string $message The message to log
	 * @return void
	 */
	public function logChat(string $channel, $sender, string $message): void {
		global $vars;
		if ($vars['show_aoml_markup'] == 0) {
			$message = preg_replace("|<font.*?>|", "", $message);
			$message = preg_replace("|</font>|", "", $message);
			$message = preg_replace("|<a\\s+href=\".+?\">|s", "[link]", $message);
			$message = preg_replace("|<a\\s+href='.+?'>|s", "[link]", $message);
			$message = preg_replace("|<a\\s+href=.+?>|s", "[link]", $message);
			$message = preg_replace("|</a>|", "[/link]", $message);
		}

		if ($channel == "Buddy") {
			$line = "[$channel] $sender $message";
		} elseif ($sender == '-1' || $sender == '4294967295') {
			$line = "[$channel] $message";
		} else {
			$line = "[$channel] $sender: $message";
		}

		$level = LegacyLogger::getLoggerLevel('INFO');
		$this->chatLogger->log($level, $line);
	}

	/**
	 * Get the relative path of the directory where logs of this bot are stored
	 */
	public function getLoggingDirectory(): string {
		try {
			$fileAppender = $this->logger->getRootLogger()->getAppender("defaultFileAppender");
			$ref = new ReflectionProperty($fileAppender, "file");
			$ref->setAccessible(true);
			$logFile = $ref->getValue($fileAppender);
			return realpath(dirname($logFile));
		} catch (Throwable $e) {
			$logDir = dirname(ini_get('error_log'));
			if (substr($logDir, 0, 1) !== '/') {
				$logDir = realpath(dirname(__DIR__, 2) . '/' . $logDir);
				if ($logDir === false) {
					$logDir = dirname(__DIR__, 2) . '/' . $logDir;
				}
			}
			return $logDir;
		}
	}

	/**
	 * Check if logging is enabled for a given category
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @return boolean
	 */
	public function isEnabledFor(string $category): bool {
		$level = LegacyLogger::getLoggerLevel($category);
		return $this->logger->isEnabledFor($level);
	}
}
