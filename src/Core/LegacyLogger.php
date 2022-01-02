<?php declare(strict_types=1);

namespace Nadybot\Core;

use JsonException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use RuntimeException;
use Stringable;

/**
 * A compatibility layer for logging
 */
class LegacyLogger {
	/** @var array<string,Logger> */
	public static array $loggers = [];

	public static array $config = [];

	/**
	 * Configuration which log channels log what
	 * @var array<array<string>>
	 * @psalm-var list<array{0:string, 1:string}>
	 */
	public static array $logLevels = [];

	/** @return array<string,Logger> */
	public static function getLoggers(?string $mask=null): array {
		if (!isset($mask)) {
			return static::$loggers;
		}
		return array_filter(
			static::$loggers,
			function(Logger $logger) use ($mask): bool {
				return fnmatch($mask, $logger->getName(), FNM_CASEFOLD);
			}
		);
	}

	/**
	 * Log a message according to log settings
	 */
	public static function log(string $category, string $channel, Stringable|string $message): void {
		$logger = static::fromConfig($channel);
		$level = static::getLoggerLevel($category);
		$logger->log($level, $message);
	}

	/**
	 * Get the Monolog log level for a Nadybot logging category
	 */
	public static function getLoggerLevel(string $category): int {
		switch (strtolower($category)) {
			case 'trace':
				return Logger::DEBUG;
			case 'debug':
				return Logger::INFO;
			case 'warn':
				return Logger::WARNING;
			case 'error':
				return Logger::ERROR;
			case 'fatal':
				return Logger::EMERGENCY;

			case 'info':
			default:
				return Logger::NOTICE;
		}
	}

	public static function getConfig(bool $noCache=false): array {
		if (!empty(static::$config) && !$noCache) {
			return static::$config;
		}
		$json = file_get_contents("./conf/logging.json");
		if ($json === false) {
			throw new RuntimeException("Unable to read logging config file");
		}
		try {
			$logStruct = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException("Unable to parse logging config", 0, $e);
		}
		if (!isset($logStruct["monolog"])) {
			throw new RuntimeException("Invalid logging config, missing \"monolog\" key");
		}
		static::$config = $logStruct["monolog"];

		// Convert the log level configuration into an ordered format
		$channels = static::$config["channels"] ?? [];
		uksort(
			$channels,
			function(string $s1, string $s2): int {
				return strlen($s2) <=> strlen($s1);
			}
		);
		static::$logLevels = [];
		foreach ($channels as $channel => $logLevel) {
			static::$logLevels []= [(string)$channel, (string)$logLevel];
		}
		return static::$config;
	}

	public static function tempLogLevelOrderride(string $mask, string $logLevel): void {
		array_unshift(static::$logLevels, [$mask, $logLevel]);
	}

	/**
	 * Re-calculate the loglevel for $logger, assign it and return old
	 * and new log level for that logger, or null if unchanged.
	 *
	 * @return array<int,string>|null
	 * @psalm-return null|array{0:string,1:string}
	 */
	public static function assignLogLevel(Logger $logger): ?array {
		$handlers = $logger->getHandlers();
		$oldLevel = null;
		$setLevel = null;
		foreach (static::$logLevels as $logLevelConf) {
			if (!fnmatch($logLevelConf[0], $logger->getName(), FNM_CASEFOLD)) {
				continue;
			}
			$newLevel = $logger->toMonologLevel($logLevelConf[1]);
			foreach ($handlers as $name => $handler) {
				if ($handler instanceof AbstractHandler) {
					$oldLevel = $logger->getLevelName($handler->getLevel());
					$handler->setLevel($newLevel);
					$setLevel = $logger->getLevelName($newLevel);
				}
			}
			if (!isset($oldLevel) || !isset($setLevel) || $oldLevel === $setLevel) {
				return null;
			}
			return [$oldLevel, $setLevel];
		}
		return null;
	}

	public static function fromConfig(string $channel): Logger {
		if (isset(static::$loggers[$channel])) {
			return static::$loggers[$channel];
		}
		$logStruct = static::getConfig();
		$formatters = static::parseFormattersConfig($logStruct["formatters"]??[]);
		$handlers = static::parseHandlersConfig($logStruct["handlers"]??[], $formatters);
		$deDuper = new DedupHandler();
		$logger = new Logger($channel, [$deDuper, ...array_values($handlers)]);
		static::assignLogLevel($logger);
		return static::$loggers[$channel] = $logger;
	}

	protected static function toClass(string $name): string {
		return join("", array_map("ucfirst", explode("_", $name)));
	}

	/**
	 * Parse th defined handlers into objects
	 *
	 * @param array<string,array> $handlers
	 * @param array<string,FormatterInterface> $formatters
	 * @return array<string,AbstractProcessingHandler>
	 */
	public static function parseHandlersConfig(array $handlers, array $formatters): array {
		$result = [];
		foreach ($handlers as $name => $config) {
			$class = "Monolog\\Handler\\".static::toClass($config["type"]) . "Handler";
			if (isset($config["options"]["fileName"])) {
				$config["options"]["fileName"] = LoggerWrapper::getLoggingDirectory() . "/" . $config["options"]["fileName"];
			}
			/** @var AbstractProcessingHandler */
			$obj = new $class(...array_values($config["options"]));
			foreach ($config["calls"]??[] as $func => $params) {
				$obj->{$func}(...array_values($params));
			}
			if (isset($config["formatter"])) {
				if (!isset($formatters[$config["formatter"]])) {
					throw new RuntimeException("The log handler {$name} uses an undeclared formatter '{$config['formatter']}'");
				}
				$obj->setFormatter($formatters[$config["formatter"]]);
			}
			$obj->pushProcessor(new PsrLogMessageProcessor(null, true));
			$result[$name] = $obj;
		}
		return $result;
	}

	/**
	 * Parse the defined formatters and return them as objects
	 *
	 * @param array<string,array> $formatters
	 * @return array<string,FormatterInterface>
	 */
	public static function parseFormattersConfig(array $formatters): array {
		$result = [];
		foreach ($formatters as $name => $config) {
			$class = "Monolog\\Formatter\\" . static::toClass($config["type"]) . "Formatter";
			/** @var FormatterInterface */
			$obj = new $class(...array_values($config["options"]));
			foreach ($config["calls"]??[] as $func => $params) {
				$obj->{$func}(...array_values($params));
			}
			$result[$name] = $obj;
		}
		return $result;
	}
}
