<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertySerializer};

/**
 * Mark a property as confidential.
 * This means it should never be displayed in clear text,
 * only redacted
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Confidential implements PropertySerializer {
	public static bool $active = false;

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		if (static::$active === false) {
			return $value;
		}
		return isset($value) ? '******' : null;
	}
}
