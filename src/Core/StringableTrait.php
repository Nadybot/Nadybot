<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Safe\json_encode;

use Safe\Exceptions\JsonException;

trait StringableTrait {
	public function __toString(): string {
		$values = [];
		$refClass = new \ReflectionClass($this);
		$props = get_object_vars($this);
		foreach ($props as $key => $value) {
			$refProp = $refClass->getProperty($key);
			if ($refProp->isInitialized($this) === false) {
				continue;
			}
			if ($value instanceof \Stringable) {
				$value = (string)$value;
			} elseif ($value instanceof \UnitEnum) {
				$value = $value->name;
			} elseif ($value instanceof \Closure) {
				$value = "<Closure>";
			} else {
				$prefix = is_object($value) ? "<" . class_basename($value) . ">" : "";
				try {
					$value = json_encode(
						$value,
						JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE
					);
				} catch (JsonException) {
					if (!is_object($value)) {
						continue;
					}
					$value = $prefix . "{}";
				}
				if (strlen($prefix) && $value === "{}") {
					$value = $prefix;
				} else {
					$value = $prefix . $value;
				}
			}
			$values []= "{$key}={$value}";
		}
		$parts = explode("\\", get_class($this));
		$class = array_pop($parts);
		return "<{$class}>{" . join(",", $values) . "}";
	}
}
