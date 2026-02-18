<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use function Safe\json_encode;

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster};
use Exception;
use Nadybot\Core\Safe;
use Nadylib\Type;
use stdClass;

/** Cast the associative array to a stdClass() object */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CastToStdClass implements PropertyCaster {
	public function cast(mixed $value, ObjectMapper $hydrator): stdClass {
		if (!is_object($value) && !is_array($value)) {
			throw new Exception('Can only recode arrays or objects');
		}

		return Safe::jsonDecode(json_encode($value), Type\instanceOfType(stdClass::class));
	}
}
