<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\File\KeyedFileMutex;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Types\LogWrapInterface;
use ReflectionClass;
use ReflectionNamedType;

use RuntimeException;

class Registry {
	/** @var array<string,object> */
	protected static array $repo = [];

	protected static ?LoggerWrapper $logger = null;

	public static function setInstance(string $name, object $obj): void {
		$name = strtolower($name);
		static::getLogger()->info("Adding instance '{class}' as '{instance}'", [
			'class' => $obj::class,
			'instance' => $name,
		]);
		static::$repo[$name] = $obj;
	}

	/** Return the name of the class without the namespace */
	public static function formatName(string $class): string {
		$class = strtolower($class);
		$array = explode('\\', $class);
		return array_pop($array);
	}

	/** Check if there is already a registered instance with name $name */
	public static function instanceExists(string $name): bool {
		$name = strtolower($name);

		return isset(Registry::$repo[$name]);
	}

	/** Check if an instance for $name is registered */
	public static function hasInstance(string $name): bool {
		$name = static::formatName($name);

		return isset(Registry::$repo[$name]);
	}

	/** Get the instance for the name $name or null if  none registered yet */
	public static function tryGetInstance(string $name, bool $reload=false): ?object {
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
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return T
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
	 * @psalm-param class-string|object $instance
	 */
	public static function injectDependencies(string|object $instance): void {
		// inject other instances that have the #[Inject] attribute
		$reflection = new ReflectionClass($instance);
		$iteration = 0;
		do {
			$iteration++;
			foreach ($reflection->getProperties() as $property) {
				if (is_string($instance) && !$property->isStatic()) {
					continue;
				}
				$injectAttrs = $property->getAttributes(NCA\Inject::class);
				if (count($injectAttrs)) {
					$injectAttr = $injectAttrs[0]->newInstance();
					$dependencyName = $injectAttr->instance;
					if (!isset($dependencyName)) {
						$type = $property->getType();
						if (!($type instanceof ReflectionNamedType)) {
							throw new RuntimeException("Cannot determine type of {$reflection->getName()}::\${$property->getName()}");
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
						$property->setAccessible(true);
						if ($property->isStatic()) {
							$property->setValue(null, $dependency);
						} elseif (is_object($instance)) {
							$property->setValue($instance, $dependency);
						}
					}
					continue;
				}

				$loggerAttrs = $property->getAttributes(NCA\Logger::class);
				if (count($loggerAttrs)) {
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
					$property->setAccessible(true);
					$logger = new LoggerWrapper($tag);
					if ($instance instanceof LogWrapInterface) {
						$closure = $reflection->getMethod('wrapLogs')->getClosure($instance);
						$logger->wrap($closure);
					}
					if ($property->isStatic()) {
						$property->setValue(null, $logger);
					} elseif (is_object($instance)) {
						$property->setValue($instance, $logger);
					}
					static::injectDependencies($logger);
				}
				$cacheAttrs = $property->getAttributes(NCA\Cache::class);
				if (count($cacheAttrs)) {
					$cacheAttr = $cacheAttrs[0]->newInstance();
					$baseDir = self::getInstance(BotConfig::class)->paths->cache;
					if (isset($cacheAttr->prefix)) {
						$baseDir .= '/' . $cacheAttr->prefix;
					}
					$cache = new FileCache(
						directory: $baseDir,
						// Or new LocalKeyedMutex()?
						mutex: new KeyedFileMutex(
							directory: $baseDir,
							filesystem: self::getInstance(Filesystem::class)->getFilesystem(),
						),
						filesystem: self::getInstance(Filesystem::class)->getFilesystem(),
					);
					$property->setAccessible(true);
					if ($property->isStatic()) {
						$property->setValue(null, $cache);
					} elseif (is_object($instance)) {
						$property->setValue($instance, $cache);
					}
				}
			}
			$reflection = $reflection->getParentClass();
		} while ($reflection !== false);
	}

	/**
	 * Get all registered instance objects
	 *
	 * @return array<string,object>
	 */
	public static function getAllInstances(): array {
		return self::$repo;
	}

	protected static function getLogger(): LoggerWrapper {
		if (isset(static::$logger)) {
			return static::$logger;
		}
		static::$logger ??= new LoggerWrapper('Core/Registry');
		// static::injectDependencies(static::$logger);
		return static::$logger;
	}
}
