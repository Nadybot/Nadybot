<?php

declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use function Safe\{json_decode, json_encode};

use Attribute;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster};
use Exception;
use stdClass;

/** Cast the associative array to a stdClass() object */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CastToStdClass implements PropertyCaster {
	public function cast(mixed $value, ObjectMapper $hydrator): stdClass {
		if (!is_object($value) && !is_array($value)) {
			throw new Exception('Can only recode arrays or objects');
		}

		/** @var \stdClass */
		$recoded = json_decode(json_encode($value), false);
		return $recoded;
	}
}
