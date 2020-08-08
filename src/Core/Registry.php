<?php declare(strict_types=1);

namespace Nadybot\Core;

use Addendum\ReflectionAnnotatedClass;

class Registry {
	/** @var array<string,object> */
	private static array $repo = [];

	public static function setInstance(string $name, object $obj): void {
		$name = strtolower($name);
		LegacyLogger::log("DEBUG", "Registry", "Adding instance '$name'");
		static::$repo[$name] = $obj;
	}

	/**
	 * Return the name of the class without the namespace
	 */
	public static function formatName(string $class): string {
		$class = strtolower($class);
		$array = explode("\\", $class);
		return array_pop($array);
	}

	/**
	 * Check if there is already a registered instance with name $name
	 */
	public static function instanceExists(string $name): bool {
		$name = strtolower($name);

		return isset(Registry::$repo[$name]);
	}

	/**
	 * Get the instance for the name $name or null if  none registered yet
	 */
	public static function getInstance(string $name, bool $reload=false): ?object {
		$name = strtolower($name);
		LegacyLogger::log("DEBUG", "Registry", "Requesting instance for '$name'");

		$instance = Registry::$repo[$name];
		if ($instance == null) {
			LegacyLogger::log("WARN", "Registry", "Could not find instance for '$name'");
		}

		return $instance;
	}

	/**
	 * Inject all fields marked with \@Inject in an object with the corresponding object instances
	 */
	public static function injectDependencies(object $instance): void {
		// inject other instances that are annotated with @Inject
		$reflection = new ReflectionAnnotatedClass($instance);
		foreach ($reflection->getProperties() as $property) {
			/** @var \Addendum\ReflectionAnnotatedProperty $property */
			if ($property->hasAnnotation('Inject')) {
				if ($property->getAnnotation('Inject')->value != '') {
					$dependencyName = $property->getAnnotation('Inject')->value;
				} else {
					$dependencyName = $property->name;
				}
				$dependency = Registry::getInstance($dependencyName);
				if ($dependency == null) {
					LegacyLogger::log("WARN", "Registry", "Could resolve dependency '$dependencyName'");
				} else {
					$instance->{$property->name} = $dependency;
				}
			} elseif ($property->hasAnnotation('Logger')) {
				if (@$property->getAnnotation('Logger')->value != '') {
					$tag = $property->getAnnotation('Logger')->value;
				} else {
					$array = explode("\\", $reflection->name);
					$tag = array_pop($array);
				}
				$instance->{$property->name} = new LoggerWrapper($tag);
			}
		}
	}

	/**
	 * Get all registered instance objects
	 * @return array<string,object>
	 */
	public static function getAllInstances(): array {
		return self::$repo;
	}
}
