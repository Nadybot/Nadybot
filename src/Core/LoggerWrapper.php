<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Monolog\Logger;
use Throwable;

/**
 * A wrapper class to monolog
 *
 */
#[NCA\Instance("logger")]
class LoggerWrapper {
	/**
	 * The actual Monolog logger
	 */
	private Logger $logger;

	/**
	 * The actual Monolog logger for tag CHAT
	 */
	private ?Logger $chatLogger = null;

	public function __construct(string $tag) {
		$this->logger = LegacyLogger::fromConfig($tag);
	}

	/** @param array<string,mixed> $context */
	public function debug(string $message, array $context=[]): void {
		$this->logger->debug($message, $context);
	}

	/** @param array<string,mixed> $context */
	public function info(string $message, array $context=[]): void {
		$this->logger->info($message, $context);
	}

	/** @param array<string,mixed> $context */
	public function notice(string $message, array $context=[]): void {
		$this->logger->notice($message, $context);
	}

	/** @param array<string,mixed> $context */
	public function warning(string $message, array $context=[]): void {
		$this->logger->warning($message, $context);
	}

	/** @param array<string,mixed> $context */
	public function error(string $message, array $context=[]): void {
		$this->logger->error($message, $context);
	}

	/** @param array<string,mixed> $context */
	public function critical(string $message, array $context=[]): void {
		$this->logger->critical($message, $context);
	}

	/** @param array<string,mixed> $context */
	public function alert(string $message, array $context=[]): void {
		$this->logger->alert($message, $context);
	}

	/** @param array<string,mixed> $context */
	public function emergency(string $message, array $context=[]): void {
		$this->logger->emergency($message, $context);
	}

	/**
	 * Log a message according to log settings
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @param string $message The message to log
	 * @param ?Throwable $throwable Optional throwable information to include in the logging event
	 * @return void
	 */
	public function log(string $category, string $message, ?Throwable $throwable=null): void {
		$level = LegacyLogger::getLoggerLevel($category);
		$context = [];
		if (isset($throwable)) {
			$context["exception"] = $throwable;
		}
		// @phpstan-ignore-next-line
		$this->logger->log($level, $message, $context);
	}

	/**
	 * Log a chat message, stripping potential HTML code from it
	 *
	 * @param string $channel Either "Buddy" or an org or private-channel name
	 * @param string|int $sender The name of the sender, or a number representing the channel
	 * @param string $message The message to log
	 * @return void
	 */
	public function logChat(string $channel, string|int $sender, string $message): void {
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

		$this->chatLogger ??= $this->logger->withName("CHAT");
		$this->chatLogger->notice($line);
	}

	/**
	 * Get the relative path of the directory where logs of this bot are stored
	 */
	public static function getLoggingDirectory(): string {
		$logDir = dirname(ini_get('error_log'));
		if (substr($logDir, 0, 1) !== '/') {
			$logDirNew = realpath(dirname(__DIR__, 2) . '/' . $logDir);
			if ($logDirNew === false) {
				$logDirNew = dirname(__DIR__, 2) . '/' . $logDir;
			}
			$logDir = $logDirNew;
		}
		return $logDir;
	}

	/**
	 * Check if logging is enabled for a given category
	 *
	 * @param string $category The log category (TRACE, DEBUG, INFO, WARN, ERROR, FATAL)
	 * @return boolean
	 */
	public function isEnabledFor(string $category): bool {
		$level = LegacyLogger::getLoggerLevel($category);
		return $this->isHandling($level);
	}

	/**
	 * Check if logging is enabled for a given level
	 *
	 * @param int $level The log level (Logger::DEBUG, etc.)
	 * @return boolean
	 */
	public function isHandling(int $level): bool {
		// @phpstan-ignore-next-line
		return $this->logger->isHandling($level);
	}
}
