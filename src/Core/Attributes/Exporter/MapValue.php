<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Exporter;

use Attribute;
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};

#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapValue implements PropertyCaster, PropertySerializer {
	private Closure $read;
	private Closure $write;

	public function __construct(
		callable $read,
		callable $write,
	) {
		$this->read = Closure::fromCallable($read);
		$this->write = Closure::fromCallable($write);
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		return ($this->read)($value);
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		return ($this->write)($value);
	}
}
