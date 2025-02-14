<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Hydrator;

use Attribute;
use Closure;
use EventSauce\ObjectHydrator\{ObjectMapper, PropertyCaster};

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class StrFuncIn implements PropertyCaster {
	/** @var list<Closure> */
	private array $hydrate;

	public function __construct(
		callable ...$hydrate,
	) {
		$this->hydrate = array_map(Closure::fromCallable(...), array_values($hydrate));
	}

	public function cast(mixed $value, ObjectMapper $hydrator): mixed {
		foreach ($this->hydrate as $closure) {
			$value = $closure($value);
		}
		return $value;
	}
}
