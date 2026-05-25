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
		$notFound = new class () {
		};
		$result = $this->last(default: $notFound);
		if ($result === $notFound) {
			throw new \Illuminate\Support\ItemNotFoundException();
		}

		/** @var TValue $result */
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

	/**
	 * Push one or more items onto the end of the collection.
	 *
	 * @param TValue ...$values
	 *
	 * @return $this
	 */
	public function push(...$values): self {
		return parent::push(...$values);
	}

	/**
	 * Put an item in the collection by key.
	 *
	 * @param TKey   $key
	 * @param TValue $value
	 *
	 * @return $this
	 */
	public function put($key, $value): self {
		return parent::put($key, $value);
	}

	/**
	 * Remove an item from the collection by key.
	 *
	 * @return $this
	 */
	public function forget(mixed $keys): self {
		return parent::forget($keys);
	}

	/**
	 * Get and remove the first N items from the collection.
	 *
	 * @param int $count
	 *
	 * @return null|static<int, TValue>|TValue
	 *
	 * @throws \InvalidArgumentException
	 */
	public function shift($count=1): mixed {
		return parent::shift($count);
	}

	/**
	 * Filter items by the given key value pair.
	 *
	 * @param callable|string $key
	 *
	 * @return static<TKey, TValue>
	 *
	 * @psalm-suppress MixedArgument
	 */
	public function where($key, mixed $operator=null, mixed $value=null): static {
		// @phpstan-ignore-next-line
		return parent::where(...func_get_args());
	}

	/**
	 * Sort through each item with a callback.
	 *
	 * @param null|(callable(TValue, TValue): int)|int $callback
	 *
	 * @return static<TKey, TValue>
	 */
	public function sort(mixed $callback=null): static {
		return parent::sort($callback); // @phpstan-ignore-line
	}

	/**
	 * Sort the collection using the given callback.
	 *
	 * @param array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string $callback
	 * @param int                                                                                                                                                     $options
	 * @param bool                                                                                                                                                    $descending
	 *
	 * @return static<TKey, TValue>
	 */
	public function sortBy(mixed $callback, mixed $options=\SORT_REGULAR, mixed $descending=false): static {
		return parent::sortBy($callback, $options, $descending); // @phpstan-ignore-line
	}

	/**
	 * Sort the collection in descending order using the given callback.
	 *
	 * @param array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string $callback
	 * @param int                                                                                                                                                     $options
	 *
	 * @return static<TKey, TValue>
	 */
	public function sortByDesc(mixed $callback, mixed $options=\SORT_REGULAR): static {
		return parent::sortByDesc($callback, $options); // @phpstan-ignore-line
	}

	/**
	 * Filter items where the value for the given key is null.
	 *
	 * @param null|string $key
	 *
	 * @return static<TKey, TValue>
	 */
	public function whereNull(mixed $key=null): static {
		return parent::whereNull($key); // @phpstan-ignore-line
	}

	/**
	 * Filter items by the given key value pair.
	 *
	 * @param string                                           $key
	 * @param \Illuminate\Contracts\Support\Arrayable|iterable $values
	 * @param bool                                             $strict
	 *
	 * @return static<TKey, TValue>
	 *
	 * @phpstan-ignore-next-line
	 */
	public function whereIn(mixed $key, mixed $values, mixed $strict=false): static {
		return parent::whereIn($key, $values, $strict); // @phpstan-ignore-line
	}

	/**
	 * Filter items by the given key value pair using strict comparison.
	 *
	 * @param string $key
	 *
	 * @return static<TKey, TValue>
	 */
	public function whereStrict($key, mixed $value): static {
		return parent::whereStrict($key, $value); // @phpstan-ignore-line
	}

	/**
	 * Run a filter over each of the items.
	 *
	 * @param null|(callable(TValue, TKey): bool) $callback
	 *
	 * @return static<TKey, TValue>
	 *
	 * @phpstan-ignore-next-line
	 */
	public function filter(?callable $callback=null): static {
		return parent::filter($callback); // @phpstan-ignore-line
	}

	/**
	 * Return only unique items from the collection array.
	 *
	 * @param null|(callable(TValue, TKey): mixed)|string $key
	 * @param bool                                        $strict
	 *
	 * @return static<TKey, TValue>
	 */
	public function unique(mixed $key=null, mixed $strict=false): static {
		return parent::unique($key, $strict); // @phpstan-ignore-line
	}

	/**
	 * Reverse items order.
	 *
	 * @return static<TKey, TValue>
	 */
	public function reverse(): static {
		return parent::reverse(); // @phpstan-ignore-line
	}

	/**
	 * Sort the collection keys.
	 *
	 * @param int  $options
	 * @param bool $descending
	 *
	 * @return static<TKey, TValue>
	 */
	public function sortKeys(mixed $options=\SORT_REGULAR, mixed $descending=false): static {
		return parent::sortKeys($options, $descending); // @phpstan-ignore-line
	}
}
