<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\DoNotSerialize;
use Nadylib\IMEX\{ExportException, JSON};

trait StringableTrait {
	private static function __valueToString(mixed $value): string {
		if ($value === null) {
			return 'null';
		} elseif ($value instanceof \Stringable) {
			return (string)$value;
		} elseif ($value instanceof \UnitEnum) {
			return $value->name;
		} elseif ($value instanceof \Closure) {
			return '<Closure>';
		} elseif ($value instanceof \DateTimeInterface) {
			return $value->format("Y-m-d\TH:i:s");
		} elseif (is_array($value) && array_is_list($value)) {
			return '[' . implode(',', array_map(self::__valueToString(...), $value)) . ']';
		} elseif (is_array($value)) {
			$values = [];
			foreach ($value as $k => $v) {
				$values []= "{$k}=" . self::__valueToString($v);
			}
			return '{' . implode(',', $values) . '}';
		}
		$prefix = is_object($value) ? '<' . class_basename($value) . '>' : '';
		try {
			$value = JSON::export($value, \JSON_INVALID_UTF8_SUBSTITUTE);
		} catch (ExportException $e) {
			if (!is_object($value)) {
				throw $e;
			}
			$value = $prefix . '{}';
		}
		if (strlen($prefix) && $value === '{}') {
			$value = $prefix;
		} else {
			$value = $prefix . $value;
		}
		return $value;
	}

	#[DoNotSerialize]
	public function __toString(): string {
		$values = [];
		$refClass = new \ReflectionClass($this);
		$props = get_object_vars($this);
		foreach ($props as $key => $value) {
			try {
				$refProp = $refClass->getProperty($key);
			} catch (\ReflectionException) {
				continue;
			}
			if ($refProp->isInitialized($this) === false) {
				continue;
			}
			$value = self::__valueToString($value);
			$values []= "{$key}={$value}";
		}
		$parts = explode('\\', static::class);
		$class = array_pop($parts);
		return "<{$class}>{" . implode(',', $values) . '}';
	}
}
