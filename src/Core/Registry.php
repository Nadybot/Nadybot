<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

class Registry {
	/** @var array<string,object> */
	protected static array $repo = [];

	protected static ?LoggerWrapper $logger = null;

	public static function setInstance(string $name, object $obj): void {
		$name = strtolower($name);
		static::getLogger()->info("Adding instance '{$name}'");
		static::$repo[$name] = $obj;
	}

	/** Return the name of the class without the namespace */
	public static function formatName(string $class): string {
		$class = strtolower($class);
		$array = explode("\\", $class);
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
	public static function getInstance(string $name, bool $reload=false): ?object {
		$name = static::formatName($name);

		$instance = Registry::$repo[$name]??null;
		if ($instance === null) {
			static::getLogger()->warning("Could not find instance for '{$name}'");
		}

		return $instance;
	}

	/** Inject all fields marked with #[Inject] in an object with the corresponding object instances */
	public static function injectDependencies(object $instance): void {
		// inject other instances that have the #[Inject] attribute
		$reflection = new ReflectionClass($instance);
		foreach ($reflection->getProperties() as $property) {
			$injectAttrs = $property->getAttributes(NCA\Inject::class);
			if (count($injectAttrs)) {
				/** @var NCA\Inject */
				$injectAttr = $injectAttrs[0]->newInstance();
				$dependencyName = $injectAttr->instance;
				if (!isset($dependencyName)) {
					$type = $property->getType();
					if (!($type instanceof ReflectionNamedType)) {
						throw new RuntimeException("Cannot determine type of {$reflection->getName()}::\${$property->getName()}");
					}
					$dependencyName = static::formatName($type->getName());
				}
				$dependency = Registry::getInstance($dependencyName);
				if ($dependency === null) {
					static::getLogger()->warning("Could not resolve dependency '{$dependencyName}' in '" . get_class($instance) ."'");
				} else {
					$property->setAccessible(true);
					$property->setValue($instance, $dependency);
				}
				continue;
			}

			$loggerAttrs = $property->getAttributes(NCA\Logger::class);
			if (count($loggerAttrs)) {
				/** @var NCA\Logger */
				$loggerAttr = $loggerAttrs[0]->newInstance();
				if (isset($loggerAttr->tag)) {
					$tag = $loggerAttr->tag;
				} else {
					$array = explode("\\", $reflection->name);
					if (preg_match("/^Nadybot\\\\Modules\\\\/", $reflection->name)) {
						$tag = join("/", array_slice($array, 2));
					} elseif (preg_match("/^Nadybot\\\\User\\\\Modules\\\\/", $reflection->name)) {
						$tag = join("/", array_slice($array, 3));
					} else {
						$tag = join("/", array_slice($array, -2));
					}
				}
				$property->setAccessible(true);
				$logger = new LoggerWrapper($tag);
				$property->setValue($instance, $logger);
				static::injectDependencies($logger);
			}
		}
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
		static::$logger ??= new LoggerWrapper("Core/Registry");
		// static::injectDependencies(static::$logger);
		return static::$logger;
	}
}
