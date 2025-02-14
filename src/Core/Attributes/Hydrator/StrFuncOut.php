<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertySerializer};

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class StrFuncOut implements PropertySerializer {
	/** @var Closure[] */
	private array $serialize;

	public function __construct(
		callable ...$serialize,
	) {
		$this->serialize = array_map(Closure::fromCallable(...), $serialize);
	}

	public function serialize(mixed $value, ObjectMapper $hydrator): mixed {
		foreach ($this->serialize as $closure) {
			$value = $closure($value);
		}
		return $value;
	}
}
