<?php declare(strict_types=1);

namespace Nadybot\Core;

use JsonException;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use RuntimeException;

/**
 * A compatibility layer for logging
 */
class LegacyLogger {
	/** @var array<string,Logger> */
	public static array $loggers = [];

	/**
	 * Log a message according to log settings
	 */
	public static function log(string $category, string $channel, $message): void {
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
		return Logger::NOTICE;
	}

	public static function fromConfig(string $channel): Logger {
		if (isset(static::$loggers[$channel])) {
			return static::$loggers[$channel];
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
		$logStruct = $logStruct["monolog"];
		$formatters = static::parseFormattersConfig($logStruct["formatters"]??[]);
		$handlers = static::parseHandlersConfig($logStruct["handlers"]??[], $formatters);
		$channels = $logStruct["channels"] ?? [];
		uksort(
			$channels,
			function(string $s1, string $s2): int {
				return strlen($s2) <=> strlen($s1);
			}
		);
		foreach ($channels as $channelMask => $level) {
			if (fnmatch($channelMask, $channel, FNM_CASEFOLD)) {
				foreach ($handlers as $name => $handler) {
					$handler->setLevel($level);
				}
				break;
			}
		}

		$logger = new Logger($channel, array_values($handlers));
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
			foreach ($config["extraArgs"]??[] as $func => $params) {
				$funcName = "set" . ucfirst($func);
				$obj->{$funcName}(...array_values($params));
			}
			if (isset($config["formatter"])) {
				if (!isset($formatters[$config["formatter"]])) {
					throw new RuntimeException("The log handler {$name} uses an undeclared formatter '{$config['formatter']}'");
				}
				$obj->setFormatter($formatters[$config["formatter"]]);
			}
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
			$result[$name] = $obj;
		}
		return $result;
	}
}
