<?php declare(strict_types=1);

namespace Nadybot\Core;

class SyncEventFactory {
	private static array $classMapping = [];

	public static function create(object $data): mixed {
		$mapping = self::getClassMapping();
		return $mapping;
	}

	private static function getClassMapping(): array {
		if (count(self::$classMapping)) {
			return self::$classMapping;
		}
		foreach (get_declared_classes() as $class) {
			if (!is_a($class, SyncEvent::class, true)) {
				continue;
			}
			self::$classMapping[$class] = true;
		}
		return self::$classMapping;
	}
}
