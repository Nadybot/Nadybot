<?php declare(strict_types=1);

namespace Nadybot\Core;

class AnonObj implements Loggable {
	/** @param array<string,mixed> $properties */
	public function __construct(
		private ?string $class=null,
		private array $properties=[],
	) {
	}

	public function toString(): string {
		$values = [];
		foreach ($this->properties as $key => $value) {
			if ($value instanceof \Closure) {
				$value = "<Closure>";
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
