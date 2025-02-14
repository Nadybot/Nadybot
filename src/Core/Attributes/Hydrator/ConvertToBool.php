<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class ConvertToBool implements PropertyCaster, PropertySerializer {
	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		return (bool)$value;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		return (bool)$value;
	}
}
