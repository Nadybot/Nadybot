<?php declare(strict_types=1);

namespace Nadybot\Core;

class AnonObj implements Loggable {
	/**
	 * @param array<string,mixed> $properties
	 * @param array<string,mixed> $smartProps
	 */
	public function __construct(
		private ?string $class=null,
		private array $properties=[],
		array $smartProps=[],
	) {
		foreach ($smartProps as $property => $value) {
			$this->setProperty($property, $value);
		}
	}

	public function setProperty(string $property, mixed $value): void {
		$keys = explode(".", $property);
		$property = array_pop($keys);
		$props = &$this->properties;
		foreach ($keys as $key) {
			if (!isset($props[$key])) {
				$props[$key] = [];
			}
			$props = &$props[$key];
		}
		$props[$property] = $value;
	}

	public function toString(): string {
		$values = [];
		foreach ($this->properties as $key => $value) {
			if ($value instanceof \Closure) {
				$value = "<Closure>";
			} elseif ($value instanceof Loggable) {
				$value = $value->toString();
			} else {
				$value = json_encode(
					$value,
					JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE
				);
			}
			$values []= "{$key}={$value}";
		}
		$class = isset($this->class) ? "<{$this->class}>" : "";
		return "{$class}{" . join(",", $values) . "}";
	}
}
