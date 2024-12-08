<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\{DefinitionProvider, IterableList, ObjectMapper, ObjectMapperCodeGenerator, ObjectMapperUsingReflection, UnableToHydrateObject, UnableToSerializeObject};
use Exception;
use Nadybot\Core\Config\BotConfig;
use Throwable;

class Hydrator {
	/** @var array<string,true> */
	private static array $badSerializers = [];

	/** @var array<string,true> */
	private static array $badHydrators = [];

	/**
	 * @template T of object
	 *
	 * @param class-string<T>     $className
	 * @param array<string,mixed> $data
	 *
	 * @return T
	 *
	 * @throws UnableToHydrateObject
	 */
	public static function hydrate(
		string $className,
		array $data,
		?DefinitionProvider $definitionProvider=null
	): object {
		$hydratorClass = self::getHydratorClass($className);
		self::ensureHydratorExists($hydratorClass, $className, $definitionProvider);
		if (class_exists($hydratorClass, false) && !isset(self::$badHydrators[$className])) {
			if (is_subclass_of($hydratorClass, ObjectMapper::class)) {
				try {
					/** @var ObjectMapper */
					$hydrator = new $hydratorClass();
					return $hydrator->hydrateObject($className, $data);
				} catch (Throwable) {
					self::$badHydrators[$className] = true;
				}
			}
		}
		$mapper = new ObjectMapperUsingReflection($definitionProvider);
		return $mapper->hydrateObject($className, $data);
	}

	/**
	 * @template T
	 *
	 * @param class-string<T>        $className
	 * @param iterable<array<mixed>> $data
	 *
	 * @return IterableList<T>
	 *
	 * @throws UnableToHydrateObject
	 */
	public static function hydrateObjects(
		string $className,
		iterable $data,
		?DefinitionProvider $definitionProvider=null
	): IterableList {
		$hydratorClass = self::getHydratorClass($className);
		self::ensureHydratorExists($hydratorClass, $className, $definitionProvider);
		if (class_exists($hydratorClass, false) && !isset(self::$badHydrators[$className])) {
			if (is_subclass_of($hydratorClass, ObjectMapper::class)) {
				try {
					/** @var ObjectMapper */
					$hydrator = new $hydratorClass();
					return $hydrator->hydrateObjects($className, $data);
				} catch (Throwable) {
					self::$badHydrators[$className] = true;
				}
			}
		}
		$mapper = new ObjectMapperUsingReflection($definitionProvider);
		return $mapper->hydrateObjects($className, $data);
	}

	public static function serialize(
		object $object,
		?DefinitionProvider $definitionProvider=null
	): mixed {
		$className = $object::class;
		$hydratorClass = self::getHydratorClass($className);
		self::ensureHydratorExists($hydratorClass, $className, $definitionProvider);
		if (class_exists($hydratorClass, false) && !isset(self::$badSerializers[$className])) {
			if (is_subclass_of($hydratorClass, ObjectMapper::class)) {
				try {
					/** @var ObjectMapper */
					$hydrator = new $hydratorClass();
					return $hydrator->serializeObject($object);
				} catch (Throwable) {
					self::$badSerializers[$className] = true;
				}
			}
		}
		$mapper = new ObjectMapperUsingReflection($definitionProvider);
		return $mapper->serializeObject($object);
	}

	/**
	 * @param object[] $objects
	 *
	 * @psalm-param list<object> $objects
	 *
	 * @return IterableList<array<mixed>>
	 *
	 * @throws UnableToSerializeObject
	 */
	public static function serializeObjects(
		array $objects,
		?DefinitionProvider $definitionProvider=null
	): IterableList {
		if (count($objects) === 0) {
			/** @psalm-suppress TooManyArguments */
			return new IterableList([]);
		}
		$className = get_class($objects[0]);
		$hydratorClass = self::getHydratorClass($className);
		self::ensureHydratorExists($hydratorClass, $className, $definitionProvider);
		if (class_exists($hydratorClass, false) && !isset(self::$badSerializers[$className])) {
			if (is_subclass_of($hydratorClass, ObjectMapper::class)) {
				try {
					/** @var ObjectMapper */
					$hydrator = new $hydratorClass();
					return $hydrator->serializeObjects($objects);
				} catch (Throwable) {
					self::$badSerializers[$className] = true;
				}
			}
		}
		$mapper = new ObjectMapperUsingReflection($definitionProvider);
		return $mapper->serializeObjects($objects);
	}

	/** @param class-string $className */
	private static function ensureHydratorExists(
		string $hydratorClass,
		string $className,
		?DefinitionProvider $definitionProvider=null
	): void {
		if (class_exists($hydratorClass, false)) {
			return;
		}

		try {
			$fs = Registry::getInstance(Filesystem::class);
			$config = Registry::getInstance(BotConfig::class);
		} catch (Exception) {
			return;
		}
		if ($config->general->enableHydratorCache === false) {
			return;
		}
		$dumper = new ObjectMapperCodeGenerator($definitionProvider);
		$code = $dumper->dump([$className], $hydratorClass);
		$fileName = $fs->tempnam($config->paths->cache, 'hydrator_');
		$fs->write($fileName, $code);
		require_once $fileName;
		$fs->deleteFile($fileName);
	}

	private static function getHydratorClass(string $className): string {
		return 'Nadybot\\Cache\\Hydrator\\Hyd_' . md5($className);
	}
}
