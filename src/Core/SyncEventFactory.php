<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\{json_decode, json_encode};

use InvalidArgumentException;
use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;
use ReflectionAttribute;
use ReflectionClass;

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
		return Hydrator::literalHydrate(
			className: $class,
			data: $data,
		);
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
