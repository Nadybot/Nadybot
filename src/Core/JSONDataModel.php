<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\preg_match;
use DateTime;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Safe\DateTime as SafeDateTime;
use UnexpectedValueException;

class JSONDataModel {
	public function fromJSON(object $data): void {
		$ref = new ReflectionClass($this);
		foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $refProp) {
			$propName = $refProp->getName();
			if (!property_exists($data, $propName)) {
				continue;
			}
			$type = $refProp->getType();
			if ($type === null) {
				$refProp->setValue($this, $data->{$propName});
				continue;
			}
			if ($type instanceof ReflectionNamedType) {
				$typeName = $type->getName();
			} elseif ($type instanceof ReflectionUnionType) {
				$refProp->setValue($this, $data->{$propName});
				continue;
			} else {
				continue;
			}
			if ($typeName === "array") {
				if (($docComment = $refProp->getDocComment()) === false) {
					$docComment = "";
				}
				$class = null;
				if (count($matches = Safe::pregMatch("/@var\s+(?:null\||\?)?array<(?:int,)?([a-zA-Z_\\\\]+)>/", $docComment))) {
					$class = $matches[1];
				} elseif (count($matches = Safe::pregMatch("/@var\s+(?:null\||\?)?([a-zA-Z_\\\\]+)\[\]/", $docComment))) {
					$class = $matches[1];
				}
				if ($class === null || preg_match("/^(int|bool|string|float|object)$/", $class)) {
					$refProp->setValue($this, $data->{$propName});
				} elseif ($class === "DateTime") {
					$refProp->setValue($this, null);
					if (isset($data->{$propName})) {
						$values = array_map(
							function (string|int|float $v): DateTime|false {
								return DateTime::createFromFormat("U", (string)floor((float)$v));
							},
							$data->{$propName}
						);
						$refProp->setValue($this, $values);
					}
				} else {
					if (isset($data->{$propName})) {
						$values = array_map(
							function (object $v) use ($class): object {
								if (class_exists($class, true) &&is_subclass_of($class, self::class)) {
									/** @psalm-suppress UnsafeInstantiation */
									$ret = new $class();
									$ret->fromJSON($v);
									return $ret;
								}
								return $v;
							},
							$data->{$propName}
						);
						$refProp->setValue($this, $values);
					} else {
						$refProp->setValue($this, null);
					}
				}
			} elseif ($type->isBuiltin() === true) {
				if ($typeName === "string") {
					if ($data->{$propName} === null) {
						$refProp->setValue($this, null);
					} else {
						$refProp->setValue($this, (string)$data->{$propName});
					}
				} else {
					$refProp->setValue($this, $data->{$propName});
				}
			} elseif ($typeName === "DateTime") {
				if (isset($data->{$propName})) {
					if (is_numeric($data->{$propName})) {
						$refProp->setValue($this, SafeDateTime::createFromFormat("U", (string)floor((float)$data->{$propName})));
					} else {
						$refProp->setValue($this, new SafeDateTime($data->{$propName}));
					}
				} else {
					$refProp->setValue($this, null);
				}
			} elseif ($typeName === "stdClass") {
				$refProp->setValue($this, $data->{$propName});
			} else {
				$value = new $typeName();
				if (method_exists($value, "fromJSON")) {
					if (!isset($data->{$propName})) {
						if ($type->allowsNull()) {
							$refProp->setValue($this, null);
						} else {
							throw new UnexpectedValueException(
								"Trying to assign a null value to ".
								"non-null property " . get_class($this).
								'::$' . $refProp->getName()
							);
						}
					} else {
						$value->fromJSON($data->{$propName});
						$refProp->setValue($this, $value);
					}
				}
			}
		}
	}
}
