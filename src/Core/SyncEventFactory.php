<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{json_decode, json_encode};

use EventSauce\ObjectHydrator\{DefinitionProvider, KeyFormatterWithoutConversion, ObjectMapperUsingReflection};
use InvalidArgumentException;

class SyncEventFactory {
	/**
	 * @var array<string,string>
	 *
	 * @psalm-var array<string,class-string<SyncEvent>>
	 */
	private static array $classMapping = [];

	/** @param array<string,mixed>|object $data */
	public static function create(array|object $data): SyncEvent {
		if (is_object($data)) {
			$data = json_decode(json_encode($data), true);
		}
		if (!is_array($data)) {
			throw new InvalidArgumentException(__CLASS__  . '::create(): Argument #1 ($data) must be an object or an array');
		}
		if (!isset($data['type'])) {
			throw new InvalidArgumentException(__CLASS__  . '::create(): Argument #1 ($data) must be a SyncEvent');
		}
		$mapping = self::getClassMapping();
		$class = $mapping[$data['type']] ?? null;
		if (!isset($class)) {
			throw new InvalidArgumentException(__CLASS__  . '::create(): Argument #1 ($data) is an unknown (Sync-)Event');
		}
		$mapper = new ObjectMapperUsingReflection(
			new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
			),
		);
		$event = $mapper->hydrateObject($class, $data);
		return $event;
	}

	/**
	 * @return array<string,string>
	 *
	 * @psalm-return array<string,class-string<SyncEvent>>
	 */
	private static function getClassMapping(): array {
		if (count(self::$classMapping)) {
			return self::$classMapping;
		}
		foreach (get_declared_classes() as $class) {
			if (!is_a($class, SyncEvent::class, true)) {
				continue;
			}
			if (!is_string($class::EVENT_MASK)) {
				continue;
			}
			self::$classMapping[$class::EVENT_MASK] = $class;
		}
		return self::$classMapping;
	}
}
