<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Safe\preg_match;
use Exception;
use Nadybot\Core\Attributes\JSON;
use Nadybot\Core\Safe;
use ReflectionClass;
use ReflectionNamedType;

use ReflectionProperty;
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
		} catch (PcreException) {
			throw new Exception("Illegal type definition: {$type}");
		}

		foreach ($types[1] as $typeMatch) {
			if ($typeMatch[1] !== 0 && substr($type, $typeMatch[1] - 1, 1) !== '|') {
				throw new Exception("Illegal type definition: {$type}");
			}
			$checkType = $typeMatch[0];

			if (static::hasIntervalType($checkType, $value)) {
				return true;
			}
			if (preg_match('/^[a-zA-Z_0-9]+$/', $checkType) && is_object($value)) {
				return true;
			}
			if (count($matches = Safe::pregMatch('/^array<([a-z]+),(.+)>$/', $checkType))) {
				if (is_object($value)) {
					$value = (array)$value;
				}
				if (is_array($value)) {
					$match = true;
					foreach ($value as $key => $arrayValue) {
						if (
							!static::matchesType($matches[1], $key)
							|| !static::matchesType($matches[2], $arrayValue)) {
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
						if (!static::matchesType($matches[1], $arrayValue)) {
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

	public static function castFromRefprop(object $result, ReflectionProperty $refProp, object $obj): void {
		$name = $refProp->getName();
		if (count($refProp->getAttributes(JSON\Ignore::class)) > 0) {
			return;
		}
		$nameAttr = $refProp->getAttributes(JSON\Name::class);
		if (count($nameAttr) > 0) {
			/** @var JSON\Name */
			$nameObj = $nameAttr[0]->newInstance();
			$name = $nameObj->name;
		}
		$docComment = $refProp->getDocComment();
		if ($docComment !== false && count($matches = Safe::pregMatch('/@var\s+([^\s]+)/', $docComment))) {
			$type = $matches[1];
		} else {
			$type = $refProp->getType();
			if ($type instanceof ReflectionNamedType) {
				if ($type->allowsNull() && $obj->{$name} === null) {
					$refProp->setValue($result, $obj->{$name});
					return;
				}
				$type = $type->getName();
			} else {
				$type = null;
			}
		}
		if (!property_exists($obj, $name)) {
			return;
		}
		if ($type === null) {
			$refProp->setValue($result, $obj->{$name});
			return;
		}
		$fullClass = static::expandClassname($type);
		if ($fullClass !== null) {
			$newObj = static::convert($fullClass, $obj->{$name});
			$refProp->setValue($result, $newObj);
			return;
		}
		// Support string[], int[] and the likes for simple types
		if (count($matches = Safe::pregMatch("/^(.+?)\[\]$/", $type)) === 2 && is_array($obj->{$name})) {
			foreach ($obj->{$name} as $value) {
				if (!static::matchesType($matches[1], $value)) {
					throw new Exception("Invalid type found: {$type}");
				}
				$className = static::expandClassname($matches[1]);
				if (isset($className)) {
					$newValue = [];
					foreach ($obj->{$name} as $value) {
						$newValue []= static::convert($className, $value);
					}
					$refProp->setValue($result, $newValue);
				}
			}
			$refProp->setValue($result, $obj->{$name});
			return;
		}
		if (static::matchesType($type, $obj->{$name})) {
			$refProp->setValue($result, $obj->{$name});
			return;
		}
		throw new Exception("Invalid type found: {$type}");
	}

	public static function convert(string $class, object $obj): object {
		$class = static::expandClassname($class);
		if ($class === null) {
			throw new Exception("Cannot find class {$class}");
		}
		$result = new $class();
		$refObj = new ReflectionClass($result);
		$refProps = $refObj->getProperties(ReflectionProperty::IS_PUBLIC);
		foreach ($refProps as $refProp) {
			static::castFromRefprop($result, $refProp, $obj);
		}
		return $result;
	}

	protected static function isAssocArray(mixed $value): bool {
		return is_array($value) && array_diff_key($value, array_keys(array_keys($value)));
	}

	protected static function hasIntervalType(string $checkType, mixed $value): bool {
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
