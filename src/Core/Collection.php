<?php declare(strict_types=1);

namespace Nadybot\Core;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends \Illuminate\Support\Collection<TKey,TValue>
 */
class Collection extends \Illuminate\Support\Collection {
	/**
	 * Create a new collection.
	 *
	 * @param null|\Illuminate\Contracts\Support\Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
	 */
	final public function __construct(mixed $items=[]) {
		parent::__construct($items);
	}

	/**
	 * Key the items of the collection by an integer
	 *
	 * @param (callable(TValue,TKey):int)|string $keyBy
	 *
	 * @return static<int,TValue>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function keyByInt(callable|string $keyBy): static {
		return $this->keyBy($keyBy);
	}

	/**
	 * Key the items of the collection by a string
	 *
	 * @param (callable(TValue,TKey):string)|string $keyBy
	 *
	 * @return static<string,TValue>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function keyByString(callable|string $keyBy): static {
		return $this->keyBy($keyBy);
	}

	/**
	 * Get the last item of a collection
	 *
	 * @return TValue
	 *
	 * @throws \Illuminate\Support\ItemNotFoundException if the item does not exist
	 */
	public function lastOrFail(): mixed {
		$result = $this->last();
		if (!isset($result)) {
			throw new \Illuminate\Support\ItemNotFoundException();
		}
		return $result;
	}

	/**
	 * Get the int values of a given key
	 *
	 * @param string|int|array<array-key,string> $value
	 *
	 * @return static<array-key,int>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function pluckInts(string|int|array $value, ?string $key=null): static {
		return $this->pluck($value, $key);
	}

	/**
	 * Get the string values of a given key
	 *
	 * @param string|int|array<array-key, string> $value
	 *
	 * @return static<array-key,string>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function pluckStrings(string|int|array $value, ?string $key=null): static {
		return $this->pluck($value, $key);
	}

	/**
	 * Get the collection of items as a plain list.
	 *
	 * @return list<TValue>
	 *
	 * @psalm-suppress InvalidReturnType
	 */
	public function toList(): array {
		/** @psalm-suppress InvalidReturnStatement */
		return array_values(
			$this->map(
				static function (mixed $value): mixed {
					if ($value instanceof Arrayable) {
						return array_values($value->toArray());
					}
					return $value;
				}
			)->all()
		);
	}

	/**
	 * Get the collection of items as a plain array.
	 *
	 * @return array<TKey, TValue>
	 */
	public function toArray(): array {
		return parent::toArray();
	}

	/**
	 * Group an associative array by a string field or using a callback.
	 *
	 * @param (callable(TValue,TKey):string)|string[]|string $groupBy
	 *
	 * @return static<string,static<int,TValue>>
	 */
	public function groupByString($groupBy): static {
		/** @var static<string,static<int,TValue>> */
		$result = $this->groupBy($groupBy, false); // @phpstan-ignore-line
		return $result;
	}

	/**
	 * Group an associative array by an int field or using a callback.
	 *
	 * @param (callable(TValue,TKey):int)|string[]|string $groupBy
	 *
	 * @return static<int,static<int,TValue>>
	 */
	public function groupByInt($groupBy): static {
		/** @var static<int,static<int,TValue>> */
		$result = $this->groupBy($groupBy, false); // @phpstan-ignore-line
		return $result;
	}
}
