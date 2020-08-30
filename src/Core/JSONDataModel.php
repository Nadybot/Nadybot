<?php declare(strict_types=1);

namespace Nadybot\Core;

use ReflectionClass;
use ReflectionProperty;
use DateTime;

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
			$typeName = $type->getName();
			if ($typeName === "array") {
				if (($docComment = $refProp->getDocComment()) === false) {
					$docComment = "";
				}
				$class = null;
				if (preg_match("/@var\s+array<(?:int,)?([a-zA-Z_\\\\]+)>/", $docComment, $matches)) {
					$class = $matches[1];
				} elseif (preg_match("/@var\s+([a-zA-Z_\\\\]+)\[\]/", $docComment, $matches)) {
					$class = $matches[1];
				}
				if ($class === null || preg_match("/^(int|bool|string|float|object)$/", $class)) {
					$refProp->setValue($this, $data->{$propName});
				} elseif ($class === "DateTime") {
					$refProp->setValue($this, null);
					if (isset($data->{$propName})) {
						$values = array_map(
							function($v) {
								return DateTime::createFromFormat("U", (string)floor((float)$v));
							},
							$data->{$propName}
						);
						$refProp->setValue($this, $values);
					}
				} else {
					if (isset($data->{$propName})) {
						$values = array_map(
							function($v) use ($class) {
								if (class_exists($class, true) &&is_subclass_of($class, self::class)) {
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
				$refProp->setValue($this, $data->{$propName});
			} elseif ($typeName === "DateTime") {
				if (isset($data->{$propName})) {
					$refProp->setValue($this, DateTime::createFromFormat("U", (string)floor((float)$data->{$propName})));
				} else {
					$refProp->setValue($this, null);
				}
			} else {
				$value = new $typeName();
				$value->fromJSON($data->{$propName});
				$refProp->setValue($this, $value);
			}
		}
	}
}
