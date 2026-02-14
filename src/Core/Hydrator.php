<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_decode;
use EventSauce\ObjectHydrator\{
	DefinitionProvider,
	IterableList,
	KeyFormatterWithoutConversion,
	ObjectMapper,
	ObjectMapperCodeGenerator,
	ObjectMapperUsingReflection,
	UnableToHydrateObject,
	UnableToSerializeObject
};
use Exception;
use Nadybot\Core\Config\BotConfig;
use Psl\Type;

use Throwable;

/** This is a purely static class to serialize or hydrate objects */
class Hydrator {
	/**
	 * Serializers that throw exceptions, and can't be cached
	 *
	 * @var array<string,true>
	 */
	private static array $badSerializers = [];

	/**
	 * Hydrators that throw exceptions, and can't be cached
	 *
	 * @var array<string,true>
	 */
	private static array $badHydrators = [];
	private static ?DefinitionProvider $defaultDefinitionProvider = null;

	/**
	 * Hydrate an object literally, meaning not changing the keys
	 *
	 * @template T of object
	 *
	 * @param class-string<T>     $className The class to hydrate to
	 * @param array<string,mixed> $data      an associative array with the data to use
	 *
	 * @return T
	 *
	 * @throws UnableToHydrateObject
	 */
	public static function literalHydrate(string $className, array $data): object {
		return self::hydrate(
			className: $className,
			data: $data,
			definitionProvider: new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
				serializePublicMethods: false,
			),
		);
	}

	/**
	 * Hydrate an object (json string to object)
	 *
	 * @template T of object
	 *
	 * @param class-string<T> $className The class to hydrate to
	 * @param string          $data      a JSON string with the data to use
	 *
	 * @return T
	 *
	 * @throws UnableToHydrateObject
	 */
	public static function hydrateString(
		string $className,
		string $data,
		?DefinitionProvider $definitionProvider=null
	): object {
		try {
			$json = json_decode($data, true);
			Type\dict(Type\string(), Type\mixed())->assert($json);
		} catch (Exception $e) {
			throw UnableToHydrateObject::dueToError($className, $e);
		}
		return self::hydrate($className, $json, $definitionProvider);
	}

	/**
	 * Hydrate an object (associative array to object)
	 *
	 * @template T of object
	 *
	 * @param class-string<T>     $className The class to hydrate to
	 * @param array<string,mixed> $data      an associative array with the data to use
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
		$definitionProvider ??= self::getDefaultDefinitionProvider();
		$hydratorClass = self::getHydratorClass($className, $definitionProvider);
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
	 * Hydrate an array of objects literally, meaning not changing the keys
	 *
	 * @template T
	 *
	 * @param class-string<T>        $className The class to hydrate to
	 * @param iterable<array<mixed>> $data      an associative array with the data to use
	 *
	 * @return IterableList<T>
	 *
	 * @throws UnableToHydrateObject
	 */
	public static function literalHydrateObjects(
		string $className,
		iterable $data,
	): IterableList {
		return self::hydrateObjects(
			className: $className,
			data: $data,
			definitionProvider: new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
				serializePublicMethods: false,
			),
		);
	}

	/**
	 * Hydrate an array of objects (list of associative arrays to list of objects)
	 *
	 * @template T
	 *
	 * @param class-string<T>        $className The class to hydrate to
	 * @param iterable<array<mixed>> $data      an associative array with the data to use
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
		$definitionProvider ??= self::getDefaultDefinitionProvider();
		$hydratorClass = self::getHydratorClass($className, $definitionProvider);
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

	/**
	 * Serialize the object literally, not changing the keys
	 *
	 * @param object $object The object to serialize
	 *
	 * @return mixed An associative array, or a list thereof
	 */
	public static function literalSerialize(object $object): mixed {
		return self::serialize(
			object: $object,
			definitionProvider: new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
				serializePublicMethods: false,
			),
		);
	}

	/**
	 * Serialize a list of objects literally, not changing the keys
	 *
	 * @param object[] $objects A list of objects to serialize
	 *
	 * @psalm-param list<object> $objects
	 *
	 * @return IterableList<array<mixed>> An iterable list with an associative array as data
	 *
	 * @throws UnableToSerializeObject
	 */
	public static function literalSerializeObjects(array $objects): IterableList {
		return self::serializeObjects(
			objects: $objects,
			definitionProvider: new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
				serializePublicMethods: false,
			),
		);
	}

	/**
	 * Serialize an object
	 *
	 * @param object $object The object to serialize
	 *
	 * @return array<array-key,mixed> An associative array with the serialized data
	 */
	public static function serialize(
		object $object,
		?DefinitionProvider $definitionProvider=null
	): mixed {
		$definitionProvider ??= self::getDefaultDefinitionProvider();
		$className = $object::class;
		$hydratorClass = self::getHydratorClass($className, $definitionProvider);
		self::ensureHydratorExists($hydratorClass, $className, $definitionProvider);
		if (class_exists($hydratorClass, false) && !isset(self::$badSerializers[$className])) {
			if (is_subclass_of($hydratorClass, ObjectMapper::class)) {
				try {
					/** @var ObjectMapper */
					$hydrator = new $hydratorClass();

					/** @var mixed[] */
					$json = $hydrator->serializeObject($object);
					return $json;
				} catch (Throwable) {
					self::$badSerializers[$className] = true;
				}
			}
		}
		$mapper = new ObjectMapperUsingReflection($definitionProvider);

		/** @var mixed[] */
		$json = $mapper->serializeObject($object);
		return $json;
	}

	/**
	 * Serialize a list of objects
	 *
	 * @param object[] $objects A list of objects to serialize
	 *
	 * @psalm-param list<object> $objects
	 *
	 * @return IterableList<array<mixed>> An iterable list of associative arrays with the data
	 *
	 * @throws UnableToSerializeObject
	 */
	public static function serializeObjects(
		array $objects,
		?DefinitionProvider $definitionProvider=null
	): IterableList {
		if (count($objects) === 0) {
			/**
			 * @var IterableList<array<mixed>>
			 *
			 * @psalm-suppress TooManyArguments
			 */
			$empty = new IterableList([]);
			return $empty;
		}
		$definitionProvider ??= self::getDefaultDefinitionProvider();
		$className = get_class($objects[0]);
		$hydratorClass = self::getHydratorClass($className, $definitionProvider);
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

	/**
	 * Compile a hydrator for the given class, unless it's already cached
	 *
	 * @param string       $hydratorClass The class name of the compiled hydrator
	 * @param class-string $className     The name of the class for which to compile a hydrator
	 */
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
		if (!class_exists($hydratorClass, false)) { // @phpstan-ignore-line
			require_once $fileName;
		}
		$fs->deleteFile($fileName);
	}

	/**
	 * Get the name of the hydrator class to hydrate objects of class `$classname`
	 *
	 * @param string $className Name of the class for which we need a hydrator
	 */
	private static function getHydratorClass(string $className, ?DefinitionProvider $definitionProvider): string {
		$dpClass = ($definitionProvider === self::$defaultDefinitionProvider) ? '_with_dp' : '';
		return 'Nadybot\\Cache\\Hydrator\\Hyd_' . md5($className) . $dpClass;
	}

	/** Get the default definition provider to use if no other is given */
	private static function getDefaultDefinitionProvider(): DefinitionProvider {
		if (!isset(self::$defaultDefinitionProvider)) {
			self::$defaultDefinitionProvider = new DefinitionProvider(
				serializePublicMethods: false,
			);
		}
		return self::$defaultDefinitionProvider;
	}
}
