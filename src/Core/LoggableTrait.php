<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\DoNotSerialize;
use ReflectionClass;
use Safe\Exceptions\JsonException;

use function Safe\json_encode;

trait LoggableTrait {
	/**
	 * Get a human-readable dump of the object and its values
	 * @param array<string,mixed> $overrides
	 * @param string[] $hide
	 */
	#[DoNotSerialize]
	private function traitedToLog(
		array $overrides=[],
		array $hide=[]
	): string {
		$values = [];
		$refClass = new ReflectionClass($this);
		foreach (get_object_vars($this) as $key => $value) {
			if (in_array($key, $hide, true)) {
				continue;
			}
			if (isset($overrides[$key])) {
				$value = $overrides[$key];
			} else {
				$refProp = $refClass->getProperty($key);
				if ($refProp->isInitialized($this) === false) {
					continue;
				}
			}
			if ($value instanceof Loggable) {
				$value = $value->toLog();
			} else {
				try {
					$value = json_encode(
						$value,
						JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE
					);
				} catch (JsonException) {
					continue;
				}
			}
			$values []= "{$key}={$value}";
		}
		$parts = explode("\\", get_class($this));
		$className = array_pop($parts);
		return "<{$className}>{" . join(",", $values) . "}";
	}

	/** Get a human-readable dump of the object and its values */
	#[DoNotSerialize]
	public function toLog(): string {
		return $this->traitedToLog();
	}
}
