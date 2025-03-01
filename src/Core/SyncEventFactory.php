<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{json_decode, json_encode};

use InvalidArgumentException;
use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Convert generic sync events and a given type into actual specific sync events
 * the bot knows and can handle.
 */
class SyncEventFactory {
	/**
	 * A mapping event type to class name
	 *
	 * @var array<string,string>
	 *
	 * @psalm-var array<string,class-string<SyncEvent>>
	 */
	private static array $classMapping = [];

	/**
	 * Create a real sync event from an arbitrary object, or associative array
	 *
	 * @param array<string,mixed>|object $data
	 */
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
		return Hydrator::literalHydrate(
			className: $class,
			data: $data,
		);
	}

	/**
	 * Parse all known classes if they define a sync event and return a mapping
	 * of sync event type to class name
	 *
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
			$refClass = new ReflectionClass($class);
			if ($refClass->isAbstract()) {
				continue;
			}
			$refAttr = $refClass->getAttributes(Event::class, ReflectionAttribute::IS_INSTANCEOF);
			if (!count($refAttr)) {
				continue;
			}

			self::$classMapping[$refAttr[0]->newInstance()->mask] = $class;
		}
		return self::$classMapping;
	}
}
