<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Sync\LocalKeyedMutex;
use Error;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	Types\LogWrapInterface,
};
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

/**
 * This is the central repository that keeps instances of all classes that are instances,
 * so they can later be inject the into other objects.
 */
class Registry {
	/**
	 * An associative array of all instances keyed by their simplified class name
	 *
	 * @var array<string,object>
	 */
	protected static array $repo = [];

	protected static ?LoggerWrapper $logger = null;

	/**
	 * Register a class instance
	 *
	 * @param string $name Simplified name of the class, without path
	 * @param object $obj  The object instance to register
	 */
	public static function setInstance(string $name, object $obj): void {
		$name = strtolower($name);
		static::getLogger()->info("Adding instance '{class}' as '{instance}'", [
			'class' => $obj::class,
			'instance' => $name,
		]);
		static::$repo[$name] = $obj;
	}

	/** Get the key in the registry for a given class name */
	public static function formatName(string $class): string {
		$class = strtolower($class);
		$array = explode('\\', $class);
		return array_pop($array);
	}

	/** Check if there is already a registered instance with name `$name` */
	public static function hasInstance(string $name): bool {
		$name = static::formatName($name);

		return isset(Registry::$repo[$name]);
	}

	/** Get the instance for the name `$name` or `null` if none registered yet */
	public static function tryGetInstance(string $name): ?object {
		$name = static::formatName($name);

		$instance = Registry::$repo[$name]??null;
		if ($instance === null) {
			static::getLogger()->warning("Could not find instance for '{instance}'", [
				'instance' => $name,
			]);
		}

		return $instance;
	}

	/**
	 * Get the instance for the given class
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $class The class to search
	 *
	 * @return T The instance for the class
	 *
	 * @throws Exception if no instance is found
	 */
	public static function getInstance(string $class): object {
		$searchClass = static::formatName($class);

		$instance = Registry::$repo[$searchClass]??null;
		if ($instance === null || !is_a($instance, $class, false)) {
			throw new Exception("Unable to find an instance of {$class}");
		}

		return $instance;
	}

	/**
	 * Inject all fields marked with #[Inject] in an object with the corresponding object instances
	 *
	 * @psalm-param class-string|object $instance Where to inject the properties
	 */
	public static function injectDependencies(string|object $instance): void {
		$reflection = new ReflectionClass($instance);
		do {
			foreach ($reflection->getProperties() as $property) {
				if (is_string($instance) && !$property->isStatic()) {
					continue;
				}
				static::handleInjectAttrs($instance, $property);
				static::handleLoggerAttrs($instance, $property);
				static::handleCacheAttrs($instance, $property);
			}
			$reflection = $reflection->getParentClass();
		} while ($reflection !== false);
	}

	/**
	 * Get all registered instance objects
	 *
	 * @return array<string,object> An associative array of all the instances,
	 *                              keyed by the class's short name
	 */
	public static function getAllInstances(): array {
		return self::$repo;
	}

	/**
	 * Inject all fields marked with #[Inject] in an object with the corresponding object instances
	 *
	 * @psalm-param class-string|object $instance Where to inject the values
	 */
	protected static function handleInjectAttrs(string|object $instance, ReflectionProperty $property): void {
		$injectAttrs = $property->getAttributes(NCA\Inject::class);
		if (count($injectAttrs) === 0) {
			return;
		}
		$injectAttr = $injectAttrs[0]->newInstance();
		$dependencyName = $injectAttr->instance;
		if (!isset($dependencyName)) {
			$type = $property->getType();
			if (!($type instanceof ReflectionNamedType)) {
				throw new RuntimeException("Cannot determine type of {$property->getDeclaringClass()->getName()}::\${$property->getName()}");
			}
			$dependencyName = static::formatName($type->getName());
		}
		$dependency = Registry::tryGetInstance($dependencyName);
		if ($dependency === null) {
			static::getLogger()->warning(
				"Could not resolve dependency '{dependencyName}' in '{class}'",
				[
					'dependencyName' => $dependencyName,
					'class' => is_string($instance) ? $instance : $instance::class,
				]
			);
		} else {
			static::injectDependency($property, $instance, $dependency);
		}
	}

	/**
	 * Inject all fields marked with #[Logger] in an object with an instance of the current logger class
	 *
	 * @psalm-param class-string|object $instance Where to inject the values
	 */
	protected static function handleLoggerAttrs(string|object $instance, ReflectionProperty $property): void {
		$loggerAttrs = $property->getAttributes(NCA\Logger::class);
		if (count($loggerAttrs) === 0) {
			return;
		}
		$reflection = $property->getDeclaringClass();
		$loggerAttr = $loggerAttrs[0]->newInstance();
		if (isset($loggerAttr->tag)) {
			$tag = $loggerAttr->tag;
		} else {
			$array = explode('\\', $reflection->name);
			if (str_starts_with($reflection->name, 'Nadybot\\Modules\\')) {
				$tag = implode('/', array_slice($array, 2));
			} elseif (str_starts_with($reflection->name, 'Nadybot\\User\\Modules\\')) {
				$tag = implode('/', array_slice($array, 3));
			} else {
				$tag = implode('/', array_slice($array, -2));
			}
		}
		$logger = new LoggerWrapper($tag);
		if ($instance instanceof LogWrapInterface) {
			/** @var \Closure(int,string|\Stringable,array<array-key,mixed>):array{int,string|\Stringable,array<array-key,mixed>} */
			$closure = $reflection->getMethod('wrapLogs')->getClosure($instance);

			$logger->wrap($closure);
		}
		static::injectDependency($property, $instance, $logger);
		static::injectDependencies($logger);
	}

	/**
	 * Inject all fields marked with #[Cache] in an object with an instance of the current CacheInterface
	 *
	 * @psalm-param class-string|object $instance Where to inject the values
	 */
	protected static function handleCacheAttrs(string|object $instance, ReflectionProperty $property): void {
		$cacheAttrs = $property->getAttributes(NCA\Cache::class);
		if (count($cacheAttrs) === 0) {
			return;
		}
		$cacheAttr = $cacheAttrs[0]->newInstance();
		$baseDir = self::getInstance(BotConfig::class)->paths->cache;
		if (isset($cacheAttr->prefix)) {
			$baseDir .= '/' . $cacheAttr->prefix;
		}
		$cache = new FileCache(
			directory: $baseDir,
			mutex: new LocalKeyedMutex(),
			filesystem: self::getInstance(Filesystem::class)->getFilesystem(),
		);
		$type = $property->getType();
		if (!($type instanceof ReflectionNamedType)
			|| $type->getName() !== CacheInterface::class) {
			throw new Error(
				"Wrong cache class for {$property->getDeclaringClass()->getName()}::".
				"{$property->getName()}. Expected " . CacheInterface::class
			);
		}
		static::injectDependency($property, $instance, $cache);
	}

	/** Get the logger for this static class */
	protected static function getLogger(): LoggerWrapper {
		if (isset(static::$logger)) {
			return static::$logger;
		}
		static::$logger ??= new LoggerWrapper('Core/Registry');
		// static::injectDependencies(static::$logger);
		return static::$logger;
	}

	/**
	 * Inject the value for a dependency into a given property
	 *
	 * @param ReflectionProperty $property   The property that needs value injection
	 * @param object|string      $instance   The instance or class name (for static properties)
	 *                                       the property is in
	 * @param object             $dependency The value to inject
	 */
	protected static function injectDependency(
		ReflectionProperty $property,
		object|string $instance,
		object $dependency
	): void {
		if ($property->isStatic()) {
			$property->setValue(null, $dependency);
		} elseif (is_object($instance)) {
			$property->setValue($instance, $dependency);
		}
	}
}
