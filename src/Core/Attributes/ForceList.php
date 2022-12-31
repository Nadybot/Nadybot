<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};

#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::IS_REPEATABLE)]
final class ForceList implements PropertyCaster, PropertySerializer {
	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		return (array)$value;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value should be an array');
		if (count($value) === 1) {
			return array_shift($value);
		}
		return $value;
	}
}
