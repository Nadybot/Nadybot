<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Exception;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class JsonImporter {
	public static function expandClassname(string $class): ?string {
		if (class_exists($class)) {
			return $class;
		}
		$allClasses = get_declared_classes();
		foreach ($allClasses as $fullClass) {
			$parts = explode("\\", $fullClass);
			if (end($parts) === $class) {
				return $fullClass;
			}
		}
		return null;
	}

	public static function matchesType(string $type, &$value): bool {
		if ($type === null) {
			return true;
		}
		if (substr($type, 0, 1) === "?") {
			if ($value === null) {
				return true;
			}
			$type = substr($type, 1);
		}
		if (preg_match_all("/\??(array<(?R),(?:(?R)(?:\|(?R))*)>|array<(?:(?R)(?:\|(?R))*)>|[a-zA-Z_]+)/", $type, $types, PREG_OFFSET_CAPTURE) === false) {
			throw new Exception("Illegal type definition: {$type}");
		}

		foreach ($types[1] as $typeMatch) {
			if ($typeMatch[1] !== 0 && substr($type, $typeMatch[1] - 1, 1) !== "|") {
				throw new Exception("Illegal type definition: {$type}");
			}
			$checkType = $typeMatch[0];

			if ($checkType === "string" && is_string($value)) {
				return true;
			}
			if ($checkType === "int" && is_int($value)) {
				return true;
			}
			if ($checkType === "float" && is_float($value)) {
				return true;
			}
			if ($checkType === "array" && is_array($value)) {
				return true;
			}
			if ($checkType === "bool" && is_bool($value)) {
				return true;
			}
			if (preg_match("/^array<([a-z]+),(.+)>$/", $checkType, $matches)) {
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
				preg_match("/^([a-z]+)\[\]$/", $checkType, $matches)
				|| preg_match("/^array<(.+)>$/", $checkType, $matches)
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
		$docComment = $refProp->getDocComment();
		$name = $refProp->getName();
		if ($docComment !== false && preg_match('/@json:ignore/', $docComment)) {
			return;
		}
		if ($docComment !== false && preg_match('/@json:name=([^\s]+)/', $docComment, $matches)) {
			$name = $matches[1];
		}
		if ($docComment !== false && preg_match('/@var\s+([^\s]+)/', $docComment, $matches)) {
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
		if (preg_match("/^(.+?)\[\]$/", $type, $matches) && is_array($obj->{$name})) {
			foreach ($obj->{$name} as $value) {
				if (!static::matchesType($type, $value)) {
					throw new Exception("Invalid type found");
				}
			}
			$refProp->setValue($result, $obj->{$name});
			return;
		}
		if (static::matchesType($type, $obj->{$name})) {
			$refProp->setValue($result, $obj->{$name});
			return;
		}
		throw new Exception("Invalid type found");
	}

	public static function convert(string $class, object $obj): ?object {
		$class = static::expandClassname($class);
		if ($class === null) {
			throw new Exception("Cannot find class $class");
		}
		$result = new $class();
		$refObj = new ReflectionClass($result);
		$refProps = $refObj->getProperties(ReflectionProperty::IS_PUBLIC);
		foreach ($refProps as $refProp) {
			static::castFromRefprop($result, $refProp, $obj);
		}
		return $result;
	}

	public static function decode(string $class, ?string $data): ?object {
		if ($data === null) {
			return null;
		}
		$obj = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
		return static::convert($class, $obj);
	}
}
