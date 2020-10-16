<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use ReflectionClass;

class JsonExporter {
	protected static function processAnnotations(ReflectionClass $refClass, object &$data, &$name, &$value): bool {
		if (!$refClass->hasProperty($name)) {
			return true;
		}
		$refProperty = $refClass->getProperty($name);
		if (!$refProperty->isInitialized($data)) {
			return false;
		}
		$docComment = $refProperty->getDocComment();
		if ($docComment === false) {
			$name = $refProperty->getName();
			return true;
		}
		if (preg_match('/@json:ignore/', $docComment)) {
			return false;
		}
		if (preg_match('/@json:name=([^\s]+)/', $docComment, $matches)) {
			$name = $matches[1];
		}
		if (preg_match('/@json:map=([^\s]+)/', $docComment, $matches)) {
			$params = explode("|", $matches[1]);
			$funcName = array_shift($params);
			if (strpos($funcName, "::") !== false) {
				$funcName = explode("::", $funcName);
			}
			if (!is_callable($funcName)) {
				return false;
			}

			$map = true;
			foreach ($params as &$param) {
				if ($param === '%s') {
					$param = $value;
				} elseif ($param === '%d') {
					$param = (int)$value;
				} else {
					try {
						$param = json_decode($param, false, 4, JSON_THROW_ON_ERROR);
					} catch (\Throwable $e) {
						$map = false;
					}
				}
			}
			if ($map) {
				$value = $funcName(...$params);
			}
		}
		return true;
	}

	public static function encode($data): string {
		if ($data === null || is_resource($data)) {
			return 'null';
		}
		if (is_scalar($data)) {
			return json_encode($data);
		}
		if (is_array($data)) {
			if (empty($data)) {
				return '[]';
			}
			if (array_keys($data) === range(0, count($data) - 1)) {
				return '[' . join(",", array_map(['static', __FUNCTION__], $data)) . ']';
			}
			$result = [];
			foreach ($data as $key => $value) {
				$result []= json_encode((string)$key) . ': ' . static::encode($value);
			}
			return "{" . join(",", $result) . "}";
		}
		if (!is_object($data)) {
			return json_encode($data);
		}
		$result = [];
		$refClass = new ReflectionClass($data);
		foreach ($data as $name => $value) {
			if (!static::processAnnotations($refClass, $data, $name, $value)) {
				continue;
			}
			$result []= json_encode((string)$name) . ':' . static::encode($value);
		}
		return '{' . join(",", $result) . '}';
	}
}
