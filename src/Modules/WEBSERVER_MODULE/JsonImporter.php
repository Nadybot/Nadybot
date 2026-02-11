<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Exception;
use Nadybot\Core\Safe;

use Safe\Exceptions\PcreException;

class JsonImporter {
	public static function expandClassname(string $class): ?string {
		if (class_exists($class)) {
			return $class;
		}
		$allClasses = get_declared_classes();
		foreach ($allClasses as $fullClass) {
			$parts = explode('\\', $fullClass);
			if (end($parts) === $class) {
				return $fullClass;
			}
		}
		return null;
	}

	public static function matchesType(string $type, mixed &$value): bool {
		if (substr($type, 0, 1) === '?') {
			if ($value === null) {
				return true;
			}
			$type = substr($type, 1);
		}
		if (count($matches = Safe::pregMatch("/^([a-zA-Z_]+)\[\]$/", $type))) {
			$type = "array<{$matches[1]}>";
		}
		try {
			$types = Safe::pregMatchOffsetAll("/\??(array<(?R),(?:(?R)(?:\|(?R))*)>|array<(?:(?R)(?:\|(?R))*)>|[a-zA-Z_]+)/", $type);
		} catch (PcreException $e) {
			throw new Exception("Illegal type definition: {$type}", 0, $e);
		}

		foreach ($types[1] as $typeMatch) {
			if ($typeMatch[1] !== 0 && substr($type, $typeMatch[1] - 1, 1) !== '|') {
				throw new Exception("Illegal type definition: {$type}");
			}
			$checkType = $typeMatch[0];

			if (self::hasIntervalType($checkType, $value)) {
				return true;
			}
			if (Safe::pregMatches('/^[a-zA-Z_0-9]+$/', $checkType) && is_object($value)) {
				return true;
			}
			if (count($matches = Safe::pregMatch('/^array<([a-z]+),(.+)>$/', $checkType))) {
				if (is_object($value)) {
					// @mago-ignore analysis:invalid-type-cast
					$value = (array)$value;
				}
				if (is_array($value)) {
					$match = true;
					foreach ($value as $key => $arrayValue) {
						if (
							!self::matchesType($matches[1], $key)
							|| !self::matchesType($matches[2], $arrayValue)) {
							$match = false;
							break;
						}
					}
					if ($match) {
						return true;
					}
				}
			} elseif (
				count($matches = Safe::pregMatch("/^([a-z]+)\[\]$/", $checkType))
				|| count($matches = Safe::pregMatch('/^array<(.+)>$/', $checkType))
			) {
				if (is_array($value)) {
					$match = true;
					foreach ($value as $arrayValue) {
						if (!self::matchesType($matches[1], $arrayValue)) {
							$match = false;
							break;
						}
					}
					if ($match) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private static function hasIntervalType(string $checkType, mixed $value): bool {
		if ($checkType === 'string' && is_string($value)) {
			return true;
		}
		if ($checkType === 'int' && is_int($value)) {
			return true;
		}
		if ($checkType === 'float' && is_float($value)) {
			return true;
		}
		if ($checkType === 'array' && is_array($value)) {
			return true;
		}
		return $checkType === 'bool' && is_bool($value);
	}
}
