<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Exporter;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Max implements PropertyCaster, PropertySerializer {
	public function __construct(
		private int $max,
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		if (isset($value) && $value > $this->max) {
			throw new InvalidArgumentException("{$value} is higher than the maximum value of {$this->max}");
		}
		return $value;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		if (isset($value) && $value > $this->max) {
			throw new InvalidArgumentException("{$value} is higher than the maximum value of {$this->max}");
		}

		return $value;
	}
}
