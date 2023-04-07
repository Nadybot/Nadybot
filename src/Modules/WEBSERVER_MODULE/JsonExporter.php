<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use DateTime;
use Nadybot\Core\Attributes\JSON;
use ReflectionClass;
use Safe\Exceptions\JsonException;

class JsonExporter {
	public static function encode(mixed $data): string {
		if ($data === null || is_resource($data) || (is_object($data) && $data instanceof \Socket)) {
			return 'null';
		}
		if (is_scalar($data)) {
			return static::jsonEncode($data);
		}
		if ($data instanceof DateTime) {
			return (string)$data->getTimestamp();
		}
		if (is_array($data)) {
			if (empty($data)) {
				return '[]';
			}
			if (array_keys($data) === range(0, count($data) - 1)) {
				// @phpstan-ignore-next-line
				return '[' . join(",", array_map([static::class, __FUNCTION__], $data)) . ']';
			}
			$result = [];
			foreach ($data as $key => $value) {
				$result []= static::jsonEncode((string)$key) . ': ' . static::encode($value);
			}
			return "{" . join(",", $result) . "}";
		}
		if (!is_object($data)) {
			return static::jsonEncode($data);
		}
		$result = [];
		$refClass = new ReflectionClass($data);
		foreach (get_object_vars($data) as $name => $value) {
			if (!static::processAnnotations($refClass, $data, $name, $value)) {
				continue;
			}
			$result []= static::jsonEncode($name) . ':' . static::encode($value);
		}
		return '{' . join(",", $result) . '}';
	}

	/** @param ReflectionClass<object> $refClass */
	protected static function processAnnotations(ReflectionClass $refClass, object &$data, string &$name, mixed &$value): bool {
		if (!$refClass->hasProperty($name)) {
			return true;
		}
		$refProperty = $refClass->getProperty($name);
		if (!$refProperty->isInitialized($data)) {
			return false;
		}
		if (count($refProperty->getAttributes(JSON\Ignore::class))) {
			return false;
		}
		$nameAttr = $refProperty->getAttributes(JSON\Name::class);
		if (count($nameAttr) > 0) {
			/** @var JSON\Name */
			$nameObj = $nameAttr[0]->newInstance();
			$name = $nameObj->name;
		}
		$mapAttr = $refProperty->getAttributes(JSON\Map::class);
		if (count($mapAttr) > 0) {
			/** @var JSON\Map */
			$mapObj = $mapAttr[0]->newInstance();
			$mapper = $mapObj->mapper;
			$value = $mapper($value);
		}
		return true;
	}

	protected static function jsonEncode(mixed $data): string {
		try {
			return \Safe\json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			return "";
		}
	}
}
