<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\DoNotSerialize;
use Nadybot\Core\Types\Loggable;
use Nadylib\IMEX\{ExportException, JSON};
use ReflectionClass;

/** This trait implements the `Loggable` interface */
trait LoggableTrait {
	/** Get a human-readable dump of the object and its values */
	#[DoNotSerialize]
	public function toLog(): string {
		return $this->traitedToLog();
	}

	/**
	 * Get a human-readable dump of the object and its values
	 *
	 * @param array<string,mixed>  $overrides
	 * @param ?array<string,mixed> $replaces
	 * @param string[]             $hide
	 */
	#[DoNotSerialize]
	private function traitedToLog(
		array $overrides=[],
		?array $replaces=null,
		array $hide=[],
		?string $class=null,
	): string {
		$values = [];
		$refClass = new ReflectionClass($this);
		$props = $replaces ?? get_object_vars($this);
		foreach ($props as $key => $value) {
			if (in_array($key, $hide, true)) {
				continue;
			}
			if (isset($overrides[$key])) {
				$value = $overrides[$key];
			} elseif (!isset($replaces)) {
				$refProp = $refClass->getProperty($key);
				if ($refProp->isInitialized($this) === false) {
					continue;
				}
			}
			if ($value instanceof Loggable) {
				$value = $value->toLog();
			} elseif ($value instanceof \Closure) {
				$value = '<Closure>';
			} else {
				try {
					$value = JSON::export($value, \JSON_INVALID_UTF8_SUBSTITUTE);
				} catch (ExportException) {
					continue;
				}
			}
			$values []= "{$key}={$value}";
		}
		if (!isset($class)) {
			$parts = explode('\\', static::class);
			$class = array_pop($parts);
		}
		return "<{$class}>{" . implode(',', $values) . '}';
	}
}
