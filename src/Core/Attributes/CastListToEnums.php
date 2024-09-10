<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes;

use function is_array;
use Attribute;
use BackedEnum;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster, PropertySerializer};

use InvalidArgumentException;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class CastListToEnums implements PropertyCaster, PropertySerializer {
	/** @param class-string<BackedEnum> $enumClass */
	public function __construct(
		private string $enumClass,
	) {
		if (!is_a($enumClass, BackedEnum::class, true)) {
			throw new InvalidArgumentException(__CLASS__ . '() Argument #1 must be a BackedEnum');
		}
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value is expected to be an array');
		$class = $this->enumClass;
		foreach ($value as $i => $item) {
			$value[$i] = $class::from($item);
		}

		return $value;
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		assert(is_array($value), 'value should be an array');

		/** @var array<array-key,BackedEnum> $value */

		foreach ($value as $i => $item) {
			$value[$i] = $item->value;
		}

		return $value;
	}
}
