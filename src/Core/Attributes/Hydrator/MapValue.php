<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};

/** Allows you to modify values on-the-fly when reading and writing */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class MapValue implements PropertyCaster, PropertySerializer {
	private Closure $read;
	private Closure $write;

	/**
	 * @param callable $read  The closure to call before assigning the value to this property
	 * @param callable $write The closure to call before serializing the value of this property
	 */
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
