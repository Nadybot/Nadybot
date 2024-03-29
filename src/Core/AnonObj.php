<?php declare(strict_types=1);

namespace Nadybot\Core;

class AnonObj implements Loggable {
	use LoggableTrait;

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

	public function toLog(): string {
		return $this->traitedToLog(class: $this->class, replaces: $this->properties);
	}
}
