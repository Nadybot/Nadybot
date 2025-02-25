<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};
use InvalidArgumentException;

/** Specify the minimum number of a value */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Min implements PropertyCaster, PropertySerializer {
	public function __construct(
		private int $min,
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		if (isset($value) && $value < $this->min) {
			throw new InvalidArgumentException("{$value} is lower than the minimum value of {$this->min}");
		}
		return $value;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		if (isset($value) && $value < $this->min) {
			throw new InvalidArgumentException("{$value} is lower than the minimum value of {$this->min}");
		}

		return $value;
	}
}
