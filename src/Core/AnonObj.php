<?php declare(strict_types=1);

namespace Nadybot\Core;

use Stringable;

/**
 * This is a special class for mocking a class for logging
 * Use this if you want the log to show that it's logging
 * a specific class, but the given class doesn't have its
 * own log functionality.
 */
class AnonObj implements Stringable {
	use LoggableTrait;

	/**
	 * Example:
	 * ```php
	 * $logObj = new AnonObj(
	 *     class: Nadybot::class,
	 *     properties: [
	 *         'ready' => true,
	 *         'data' => [
	 *             'name' => 'Nadybot'
	 *         ]
	 *     ],
	 *     smartProps: [
	 *         'data.name' => 'Nadybot2'
	 *     ]
	 * );
	 * ```
	 *
	 * @param ?string             $class      The name of the class to pretend to be logging
	 * @param array<string,mixed> $properties The properties to log as associative string
	 * @param array<string,mixed> $smartProps An associative array of [`key` => `value`],
	 *                                        but `key` can contain dots to traverse into
	 *                                        associative arrays
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

	public function __toString(): string {
		return $this->traitedToLog(class: $this->class, replaces: $this->properties);
	}

	/**
	 * Set the property `$property` to `$value`, but parse dots in `$property` as
	 * a subkey-selector for associative arrays. So `'one.two'` assumes that
	 * we have a property `one` with an associative array, and we want to change
	 * the value for the key `two` in it.
	 */
	public function setProperty(string $property, mixed $value): void {
		$keys = explode('.', $property);
		$property = array_pop($keys);

		/** @psalm-suppress UnsupportedPropertyReferenceUsage */
		$props = &$this->properties;
		foreach ($keys as $key) {
			if (!isset($props[$key])) {
				$props[$key] = [];
			}
			$props = &$props[$key];
		}
		$props[$property] = $value;
	}
}
