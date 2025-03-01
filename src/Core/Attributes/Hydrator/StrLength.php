<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};
use InvalidArgumentException;

/** Specify that a string must be between $min and $max characters long */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class StrLength implements PropertyCaster, PropertySerializer {
	public function __construct(
		private int $min,
		private int $max,
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		if (!isset($value)) {
			return null;
		}
		if (!is_string($value)) {
			throw new InvalidArgumentException('Must be a string');
		}
		if (strlen($value) < $this->min) {
			throw new InvalidArgumentException("\"{$value}\" is shorter than the minimum length of {$this->min}");
		}
		if (strlen($value) > $this->max) {
			throw new InvalidArgumentException("\"{$value}\" is longer than the maximum length of {$this->max}");
		}
		return $value;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		if (!isset($value)) {
			return null;
		}
		if (!is_string($value)) {
			throw new InvalidArgumentException('Must be a string');
		}
		if (strlen($value) < $this->min) {
			throw new InvalidArgumentException("The minimum length is {$this->min}");
		}
		if (strlen($value) > $this->max) {
			throw new InvalidArgumentException("The maximum length is {$this->max}");
		}
		return $value;
	}
}
