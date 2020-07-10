<?php

namespace Budabot\Core;

use Budabot\Core\Registry;
use Logger;

/**
 * A wrapper class to log4php
 *
 * @Instance("logger")
 */
class LoggerWrapper {
	/**
	 * The actual log4php logger
	 *
	 * @var \Logger
	 */
	private $logger;

	/**
	 * The actual log4php logger for tag CHAT
	 *
	 * @var \Logger
	 */
	private $chatLogger;

	public function __construct($tag) {
		$this->logger = Logger::getLogger($tag);
		$this->chatLogger = Logger::getLogger('CHAT');
		Registry::setInstance("logger", $this);
	}

	/**
	 * Log a message according to log settings
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @param mixed $message The message to log
	 * @param Exception $throwable Optional throwable information to include in the logging event
	 * @return void
	 */
	public function log($category, $message, $throwable=null) {
		$level = LegacyLogger::getLoggerLevel($category);
		$this->logger->log($level, $message, $throwable);
	}

	/**
	 * Log a chat message, stripping potential HTML code from it
	 *
	 * @param sring $channel Either "Buddy" or an org or privchannel name
	 * @param string|int $sender The name of the sender, or a number representing the channel
	 * @param string $message The message to log
	 * @return void
	 */
	public function logChat($channel, $sender, $message) {
		global $vars;
		if ($vars['show_aoml_markup'] == 0) {
			$message = preg_replace("|<font(.+)>|U", "", $message);
			$message = preg_replace("|</font>|U", "", $message);
			$message = preg_replace("|<a(\\s+)href=\"(.+)\">|sU", "[link]", $message);
			$message = preg_replace("|<a(\\s+)href='(.+)'>|sU", "[link]", $message);
			$message = preg_replace("|</a>|U", "[/link]", $message);
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
	 *
	 * @return string
	 */
	public function getLoggingDirectory() {
		global $vars;
		return "./logs/{$vars['name']}.{$vars['dimension']}";
	}

	/**
	 * Check if logging is enabled for a given category
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @return boolean
	 */
	public function isEnabledFor($category) {
		$level = LegacyLogger::getLoggerLevel($category);
		return $this->logger->isEnabledFor($level);
	}
}
