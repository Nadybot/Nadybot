<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Exporter;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};
use InvalidArgumentException;
use Nadybot\Core\Safe;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class StrFormat implements PropertyCaster, PropertySerializer {
	public function __construct(
		private string $regexp,
	) {
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		return $this->validate($value);
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		return $this->validate($value);
	}

	private function validate(mixed $value): ?string {
		if (!isset($value)) {
			return null;
		}
		if (!is_string($value)) {
			throw new InvalidArgumentException('Must be a string');
		}
		if (!Safe::pregMatch(chr(1) . $this->regexp . chr(1), $value)) {
			throw new InvalidArgumentException('Wrong format');
		}
		return $value;
	}
}
