<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\DB;

use Attribute;

/**
 * After reading a value from the database,
 * modify it with the given function before assigning it to the property
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MapRead {
	/** @var callable[] */
	private array $mappers = [];

	public function __construct(callable ...$callbacks) {
		$this->mappers = $callbacks;
	}

	public function map(mixed $value): mixed {
		foreach ($this->mappers as $callback) {
			$value = $callback($value);
		}
		return $value;
	}
}
