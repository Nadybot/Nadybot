<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_decode;
use Monolog\{
	Formatter\FormatterInterface,
	Handler\AbstractHandler,
	Handler\AbstractProcessingHandler,
	Logger,
};
use Nadybot\Core\{
	Attributes as NCA,
	Routing\Source,
};
use RuntimeException;
use Safe\Exceptions\JsonException;
use SplObjectStorage;

/**
 * A compatibility layer for logging
 */
#[
	NCA\EmitsMessages(Source::LOG, 'emergency'),
	NCA\EmitsMessages(Source::LOG, 'alert'),
	NCA\EmitsMessages(Source::LOG, 'critical'),
	NCA\EmitsMessages(Source::LOG, 'error'),
	NCA\EmitsMessages(Source::LOG, 'warning'),
	NCA\EmitsMessages(Source::LOG, 'notice'),
]
class LegacyLogger {
	/** @var array<string,Logger> */
	public static array $loggers = [];

	/** @var array<string,mixed> */
	public static array $config = [];

	public static Filesystem $fs;

	/**
	 * Configuration which log channels log what
	 *
	 * @var array<array<string>>
	 *
	 * @psalm-var list<array{0:string, 1:string}>
	 */
	public static array $logLevels = [];

	/** @var SplObjectStorage<AbstractHandler,null> */
	public static SplObjectStorage $dynamicHandlers;

	/**
	 * Get all the loggers that match a given search mask (e.g. `webserver/*`)
	 *
	 * @return array<string,Logger>
	 */
	public static function getLoggers(?string $mask=null): array {
		if (!isset($mask)) {
			return static::$loggers;
		}
		return array_filter(
			static::$loggers,
			static function (Logger $logger) use ($mask): bool {
				return fnmatch($mask, $logger->getName(), \FNM_CASEFOLD);
			}
		);
	}

	/**
	 * Get the Monolog log level for a Nadybot logging category
	 *
	 * @phpstan-return 100|200|250|300|400|500|550|600
	 */
	public static function getLoggerLevel(string $category): int {
		return match (strtolower($category)) {
			'trace' => Logger::DEBUG,
			'debug' => Logger::INFO,
			'warn'  => Logger::WARNING,
			'error' => Logger::ERROR,
			'fatal' => Logger::EMERGENCY,
			default => Logger::NOTICE,
		};
	}

	/**
	 * Get the logging configuration of Nadybot as an associative array
	 *
	 * @return array<string,mixed>
	 */
	public static function getConfig(bool $noCache=false): array {
		if (!isset(static::$dynamicHandlers)) {
			/** @var SplObjectStorage<AbstractHandler,null> */
			$dynamicHandlers = new SplObjectStorage();
			static::$dynamicHandlers = $dynamicHandlers;
		}
		if (count(static::$config) > 0 && !$noCache) {
			return static::$config;
		}
		$configFile = BotRunner::getArguments()->logConfig ?? './conf/logging.json';
		$json = self::$fs->read($configFile);
		try {
			$logStruct = json_decode($json, true, 512);
		} catch (JsonException $e) {
			throw new RuntimeException('Unable to parse logging config', 0, $e);
		}
		if (!isset($logStruct['monolog'])) {
			throw new RuntimeException('Invalid logging config, missing "monolog" key');
		}
		static::$config = $logStruct['monolog'];

		// Convert the log level configuration into an ordered format
		$channels = static::$config['channels'] ?? [];
		uksort(
			$channels,
			static function (string $s1, string $s2): int {
				return strlen($s2) <=> strlen($s1);
			}
		);
		static::$logLevels = [];
		$verbosity = BotRunner::getArguments()->verbosity;
		if ($verbosity === 1) {
			static::$logLevels []= ['*', 'info'];
		} elseif ($verbosity > 1) {
			static::$logLevels []= ['*', 'debug'];
		}
		foreach ($channels as $channel => $logLevel) {
			static::$logLevels []= [(string)$channel, (string)$logLevel];
		}
		return static::$config;
	}

	/**
	 * Temporary override the log level for the given mask with the given log level
	 *
	 * @param string $mask     The mask to change the log level for
	 * @param string $logLevel The new log level for the matching loggers
	 */
	public static function tempLogLevelOrderride(string $mask, string $logLevel): void {
		array_unshift(static::$logLevels, [$mask, $logLevel]);
	}

	/** Restore the last stored log level config */
	public static function tempLogLevelRemove(): void {
		if (count(static::$logLevels) > 1) {
			array_shift(static::$logLevels);
		}
	}

	/**
	 * Re-calculate the log level for $logger, assign it and return old
	 * and new log level for that logger, or null if unchanged.
	 *
	 * @return null|array<int,string>
	 *
	 * @psalm-return null|array{0:string,1:string}
	 */
	public static function assignLogLevel(Logger $logger): ?array {
		$handlers = $logger->getHandlers();
		$oldLevel = null;
		$setLevel = null;
		foreach (static::$logLevels as $logLevelConf) {
			if (!fnmatch($logLevelConf[0], $logger->getName(), \FNM_CASEFOLD)) {
				continue;
			}

			/**
			 * @psalm-suppress ArgumentTypeCoercion
			 *
			 * @phpstan-ignore-next-line
			 */
			$newLevel = $logger::toMonologLevel($logLevelConf[1]);
			foreach ($handlers as $name => $handler) {
				if ($handler instanceof AbstractHandler) {
					if (static::$dynamicHandlers->contains($handler)) {
						$oldLevel = $logger::getLevelName($handler->getLevel());
						$handler->setLevel($newLevel);
						$setLevel = $logger::getLevelName($newLevel);
					}
				}
			}
			if (!isset($oldLevel) || !isset($setLevel) || $oldLevel === $setLevel) {
				return null;
			}
			return [$oldLevel, $setLevel];
		}
		return null;
	}

	/** Create a new Monolog logger for a given channel */
	public static function fromConfig(string $channel): Logger {
		if (isset(static::$loggers[$channel])) {
			return static::$loggers[$channel];
		}
		$logStruct = static::getConfig();
		$formatters = static::parseFormattersConfig($logStruct['formatters']??[]);
		$handlers = static::parseHandlersConfig($logStruct['handlers']??[], $formatters);
		$logger = new Logger($channel, [...array_values($handlers)]);
		static::assignLogLevel($logger);
		return static::$loggers[$channel] = $logger;
	}

	/**
	 * Parse the defined handlers into objects
	 *
	 * @param iterable<string,mixed>           $handlers
	 * @param array<string,FormatterInterface> $formatters
	 *
	 * @return array<string,AbstractProcessingHandler>
	 */
	public static function parseHandlersConfig(iterable $handlers, array $formatters): array {
		$result = [];
		foreach ($handlers as $name => $config) {
			$class = 'Monolog\\Handler\\'.static::toClass($config['type']) . 'Handler';
			if (isset($config['options']['fileName'])) {
				$config['options']['fileName'] = LoggerWrapper::getLoggingDirectory() . '/' . $config['options']['fileName'];
			}
			$dynamic = false;
			if (isset($config['options']['level']) && $config['options']['level'] === 'default') {
				$config['options']['level'] = 'notice';
				$dynamic = true;
			}

			/** @var AbstractProcessingHandler */
			$obj = new $class(...array_values($config['options']));
			if ($dynamic) {
				static::$dynamicHandlers->attach($obj);
			}
			foreach ($config['calls']??[] as $func => $params) {
				$callable = [$obj, $func];
				if (is_callable($callable)) {
					call_user_func_array($callable, array_values($params));
				} else {
					throw new \Error('Call to undefined method ' . $obj::class . "::{$func}()");
				}
			}
			if (isset($config['formatter'])) {
				if (!isset($formatters[$config['formatter']])) {
					throw new RuntimeException("The log handler {$name} uses an undeclared formatter '{$config['formatter']}'");
				}
				$obj->setFormatter($formatters[$config['formatter']]);
			}
			$removeUsedVariables = $config['removeUsedVariables'] ?? true;
			$obj->pushProcessor(new PsrLogMessageProcessor(null, $removeUsedVariables));
			$result[$name] = $obj;
		}
		return $result;
	}

	/**
	 * Parse the defined formatters and return them as objects
	 *
	 * @param iterable<string,array<mixed>> $formatters
	 *
	 * @return array<string,FormatterInterface>
	 */
	public static function parseFormattersConfig(iterable $formatters): array {
		$result = [];
		foreach ($formatters as $name => $config) {
			$class = 'Monolog\\Formatter\\' . static::toClass($config['type']) . 'Formatter';

			/** @var FormatterInterface */
			$obj = new $class(...array_values($config['options']));
			foreach ($config['calls']??[] as $func => $params) {
				$callable = [$obj, $func];
				if (is_callable($callable)) {
					call_user_func_array($callable, array_values($params));
				} else {
					throw new \Error('Call to undefined method ' . $obj::class . "::{$func}()");
				}
			}
			$result[$name] = $obj;
		}
		return $result;
	}

	/** Register the message emitters for all of our loglevels */
	public static function registerMessageEmitters(MessageHub $hub): void {
		$refClass = new \ReflectionClass(self::class);
		foreach ($refClass->getAttributes(NCA\EmitsMessages::class) as $attr) {
			$obj = $attr->newInstance();
			$hub->registerMessageEmitter($obj);
		}
	}

	/**
	 * Convert snake_case to PascalCase
	 *
	 * @param string $name The class name in snake case
	 *
	 * @return string The class name in pascal case
	 */
	protected static function toClass(string $name): string {
		return implode('', array_map('ucfirst', explode('_', $name)));
	}
}
