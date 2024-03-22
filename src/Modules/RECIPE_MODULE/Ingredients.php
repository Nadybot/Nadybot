<?php declare(strict_types=1);

namespace Nadybot\Modules\RECIPE_MODULE;

use ArrayIterator;
use IteratorIterator;

/**
 * @extends IteratorIterator<int,Ingredient,ArrayIterator>
 */
class Ingredients extends IteratorIterator {
	public function __construct(Ingredient ...$ingredients) {
		/** @psalm-suppress InvalidArgument */
		parent::__construct(new ArrayIterator($ingredients));
	}

	public function current(): Ingredient {
		return parent::current();
	}

	public function add(Ingredient $ingredient): void {
		/** @var ArrayIterator<int,Ingredient> */
		$inner = $this->getInnerIterator();
		$inner->append($ingredient);
	}

	public function count(): int {
		/** @var ArrayIterator<int,Ingredient> */
		$inner = $this->getInnerIterator();
		return $inner->count();
	}

	public function last(): ?Ingredient {
		/** @var ArrayIterator<int,Ingredient> */
		$inner = $this->getInnerIterator();
		return $inner->offsetGet($this->count()-1);
	}

	/** Get the highest amount required of any ingredient */
	public function getMaxAmount(): int {
		return array_reduce(
			iterator_to_array($this->getInnerIterator()),
			static function (int $max, Ingredient $ing): int {
				return max($max, $ing->amount);
			},
			1
		);
	}
}
