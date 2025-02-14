<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};

#[Attribute(Attribute::TARGET_PARAMETER)]
final class OptionFlag implements PropertyCaster, PropertySerializer {
	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		return $value === false ? true : false;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		return $value === true ? false : null;
	}
}
