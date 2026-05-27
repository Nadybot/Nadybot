<?php declare(strict_types=1);

namespace Nadybot\Core;

use Illuminate\Support\{Collection as SupportCollection, ItemNotFoundException as SupportItemNotFoundException};
use Nadybot\Core\Exceptions\ItemNotFoundException;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @implements \IteratorAggregate<TKey,TValue>
 * @implements \ArrayAccess<TKey,TValue>
 */
class Collection implements \Countable, \IteratorAggregate, \ArrayAccess, \JsonSerializable {
	/** @var SupportCollection<TKey,TValue> */
	private SupportCollection $collection;

	// ─── Construction ─────────────────────────────────────────────────

	/**
	 * Create a new collection.
	 *
	 * @param iterable<TKey,TValue> $items
	 */
	final public function __construct(iterable $items=[]) {
		if ($items instanceof self) {
			$items = $items->toArray();
		}
		if (!($items instanceof SupportCollection)) {
			$items = new SupportCollection($items);
		}
		$this->collection = $items;
	}

	/**
	 * Create a new collection.
	 *
	 * @template TMakeKey of array-key
	 * @template TMakeValue
	 *
	 * @param iterable<TMakeKey,TMakeValue> $items
	 *
	 * @return self<TMakeKey,TMakeValue>
	 */
	public static function make(iterable $items=[]): self {
		return new self($items);
	}

	// ─── Access / Retrieval ────────────────────────────────────────────

	/**
	 * Get an item from the collection by key.
	 *
	 * @template TGetDefault
	 *
	 * @param TKey        $key
	 * @param TGetDefault $default
	 *
	 * @return TValue|TGetDefault
	 */
	public function get(string|int $key, mixed $default=null): mixed {
		return $this->collection->get($key, $default);
	}

	/**
	 * Determine if an item exists in the collection by key.
	 *
	 * @param TKey $key
	 */
	public function has(string|int $key): bool {
		return $this->collection->has($key);
	}

	/**
	 * Get the first item from the collection passing the given truth test.
	 *
	 * @template TFirstDefault
	 *
	 * @param null|(callable(TValue,TKey):bool) $callback
	 * @param TFirstDefault                     $default
	 *
	 * @return TValue|TFirstDefault
	 */
	public function first(?callable $callback=null, mixed $default=null): mixed {
		return $this->collection->first($callback, $default);
	}

	/**
	 * Get the first item in the collection but throw an exception if no matching items exist.
	 *
	 * @param null|(callable(TValue,TKey):bool)|string $key
	 *
	 * @return TValue
	 *
	 * @throws ItemNotFoundException
	 */
	public function firstOrFail(null|callable|string $key=null, mixed $operator=null, mixed $value=null): mixed {
		try {
			/** @psalm-suppress MixedArgument */
			$result = $this->collection->firstOrFail(...func_get_args());
		} catch (SupportItemNotFoundException $e) {
			throw new ItemNotFoundException(previous: $e);
		}

		/** @var TValue $result */
		return $result;
	}

	/**
	 * Get the first item by the given key value pair.
	 *
	 * @return null|TValue
	 */
	public function firstWhere(callable|string $key, mixed $operator=null, mixed $value=null): mixed {
		/** @psalm-suppress MixedArgument */
		return $this->collection->firstWhere(...func_get_args());
	}

	/**
	 * Get the last item from the collection.
	 *
	 * @template TLastDefault
	 *
	 * @param null|(callable(TValue,TKey): bool) $callback
	 * @param TLastDefault                       $default
	 *
	 * @return TValue|TLastDefault
	 */
	public function last(?callable $callback=null, mixed $default=null): mixed {
		return $this->collection->last($callback, $default);
	}

	/**
	 * Get the last item of a collection
	 *
	 * @return TValue
	 *
	 * @throws ItemNotFoundException if the item does not exist
	 */
	public function lastOrFail(): mixed {
		$notFound = new class () {
		};
		$result = $this->collection->last(default: $notFound);
		if ($result === $notFound) {
			throw new ItemNotFoundException();
		}

		/** @var TValue $result */
		return $result;
	}

	// ─── ArrayAccess ────────────────────────────────────────────────────

	/**
	 * Determine if an item exists at an offset.
	 *
	 * @param TKey $key
	 */
	public function offsetExists(mixed $key): bool {
		return $this->collection->offsetExists($key);
	}

	/**
	 * Get an item at a given offset.
	 *
	 * @param TKey $key
	 *
	 * @return TValue
	 */
	public function offsetGet(mixed $key): mixed {
		return $this->collection->offsetGet($key);
	}

	/**
	 * Set the item at a given offset.
	 *
	 * @param null|TKey $key
	 * @param TValue    $value
	 */
	public function offsetSet(mixed $key, mixed $value): void {
		$this->collection->offsetSet($key, $value);
	}

	/**
	 * Unset the item at a given offset.
	 *
	 * @param TKey $key
	 */
	public function offsetUnset(mixed $key): void {
		$this->collection->offsetUnset($key);
	}

	// ─── Inspection ─────────────────────────────────────────────────────

	/** Count the number of items in the collection. */
	public function count(): int {
		return $this->collection->count();
	}

	/** Check if the collection is empty. */
	public function isEmpty(): bool {
		return $this->collection->isEmpty();
	}

	/** Check if the collection is not empty. */
	public function isNotEmpty(): bool {
		return $this->collection->isNotEmpty();
	}

	/** Determine if the collection contains a single item. */
	public function containsOneItem(): bool {
		return $this->count() === 1;
	}

	/**
	 * Determine if an item exists in the collection.
	 *
	 * @param (callable(TValue,TKey):bool)|TValue|string $key
	 */
	public function contains(mixed $key, mixed $operator=null, mixed $value=null): bool {
		/** @psalm-suppress MixedArgument */
		return $this->collection->contains(...func_get_args());
	}

	/**
	 * Determine if an item exists, using strict comparison.
	 *
	 * @param (callable(TValue):bool)|TValue|array-key $key
	 * @param null|TValue                              $value
	 */
	public function containsStrict(mixed $key, mixed $value=null): bool {
		/** @psalm-suppress MixedArgument */
		return $this->collection->containsStrict(...func_get_args());
	}

	// ─── Modification ───────────────────────────────────────────────────

	/**
	 * Push one or more items onto the end of the collection.
	 *
	 * @param TValue ...$values
	 *
	 * @return $this
	 */
	public function push(mixed ...$values): self {
		$this->collection->push(...$values);
		return $this;
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
		$this->collection->put($key, $value);
		return $this;
	}

	/**
	 * Get and remove the last item from the collection.
	 *
	 * @return null|TValue
	 *
	 * @psalm-suppress InvalidReturnType,InvalidReturnStatement
	 */
	public function pop(): mixed {
		// @phpstan-ignore-next-line
		return $this->collection->pop();
	}

	/**
	 * Get and remove the first item from the collection.
	 *
	 * @return ?TValue
	 *
	 * @psalm-suppress InvalidReturnType,InvalidReturnStatement
	 */
	public function shift(): mixed {
		// @phpstan-ignore-next-line
		return $this->collection->shift(1);
	}

	/**
	 * Remove an item from the collection by key.
	 *
	 * @param iterable<string|int>|string|int $keys List of keys to remove
	 *
	 * @return $this
	 */
	public function remove(iterable|string|int $keys): self {
		$this->collection->forget($keys);
		return $this;
	}

	// ─── Transformation ───────────────────────────────────────────────

	/**
	 * Run a map over each of the items.
	 *
	 * @template TMapValue
	 *
	 * @param callable(TValue,TKey):TMapValue $callback
	 *
	 * @return self<TKey,TMapValue>
	 */
	public function map(callable $callback): self {
		return new self($this->collection->map($callback));
	}

	/**
	 * Run a dictionary map over the items.
	 *
	 * The callback should return an associative array with a single key/value pair.
	 *
	 * @template TMapToDictionaryKey of array-key
	 * @template TMapToDictionaryValue
	 *
	 * @param callable(TValue,TKey):array<TMapToDictionaryKey,TMapToDictionaryValue> $callback
	 *
	 * @return self<TMapToDictionaryKey,array<int,TMapToDictionaryValue>>
	 */
	public function mapToDictionary(callable $callback): self {
		return new self($this->collection->mapToDictionary($callback));
	}

	/**
	 * Get a flattened array of the items in the collection.
	 *
	 * @return self<int,mixed>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion,MixedArgument
	 */
	public function flatten(int $depth=\PHP_INT_MAX): self {
		$flatten = static function (iterable $items, int $depth) use (&$flatten): array {
			$result = [];
			foreach ($items as $item) {
				if (($item instanceof self || is_array($item)) && $depth > 0) {
					$values = $item instanceof self ? $item->toArray() : $item;
					$result = array_merge($result, $flatten($values, $depth - 1));
				} else {
					$result[] = $item;
				}
			}
			return $result;
		};

		return new self($flatten($this->collection, $depth));
	}

	/**
	 * Reverse items order.
	 *
	 * @return self<TKey,TValue>
	 */
	public function reverse(): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->reverse());
	}

	/**
	 * Get the keys of the collection items.
	 *
	 * @return self<int,TKey>
	 */
	public function keys(): self {
		return new self($this->collection->keys());
	}

	/**
	 * Reset the keys on the underlying array.
	 *
	 * @return self<int,TValue>
	 */
	public function values(): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->values());
	}

	/**
	 * Slice the underlying collection array.
	 *
	 * @return self<TKey,TValue>
	 */
	public function slice(int $offset, ?int $length=null): self {
		return new self($this->collection->slice($offset, $length));
	}

	/**
	 * Merge the collection with the given items.
	 *
	 * @param iterable<TKey,TValue> $items
	 *
	 * @return self<TKey,TValue>
	 */
	public function merge(iterable $items): self {
		return new self($this->collection->merge($items));
	}

	/**
	 * Push all of the given items onto the collection.
	 *
	 * @template TConcatKey of array-key
	 * @template TConcatValue
	 *
	 * @param iterable<TConcatKey,TConcatValue> $source
	 *
	 * @return self<TKey|TConcatKey,TValue|TConcatValue>
	 */
	public function concat(iterable $source): self {
		return new self($this->collection->concat($source));
	}

	/**
	 * Get the items in the collection that are not present in the given items.
	 *
	 * @param iterable<array-key,TValue> $items
	 *
	 * @return self<TKey,TValue>
	 */
	public function diff(iterable $items): self {
		/** @psalm-suppress MixedArgument */
		return new self($this->collection->diff(...func_get_args()));
	}

	/** Join all items from the collection using a string. The final items can use a separate glue string. */
	public function join(string $glue, string $finalGlue=''): string {
		return $this->collection->join($glue, $finalGlue);
	}

	/**
	 * Get the collection of items as a plain array.
	 *
	 * @return array<TKey,TValue>
	 */
	public function toArray(): array {
		return array_map(self::convertToArray(...), $this->collection->all());
	}

	/**
	 * Get the collection of items as a plain list.
	 *
	 * @return list<TValue>
	 *
	 * @psalm-suppress InvalidReturnType
	 */
	public function toList(): array {
		/**
		 * @psalm-suppress InvalidReturnStatement
		 *
		 * @phpstan-ignore-next-line
		 */
		return array_values(
			$this->collection->map(
				/**
				 * @param TValue $value
				 *
				 * @return TValue|list<TValue>
				 */
				static function (mixed $value): mixed {
					if ($value instanceof self) {
						return array_values($value->toArray());
					}
					return $value;
				}
			)->all()
		);
	}

	// ─── Filtering ──────────────────────────────────────────────────────

	/**
	 * Run a filter over each of the items.
	 *
	 * If no callback is given, all falsy values (null, false, 0, '', []) are removed.
	 *
	 * @param callable(TValue,TKey):bool $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function filter(callable $callback): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->filter($callback));
	}

	/**
	 * Remove all null values from the collection.
	 *
	 * @return self<TKey,TValue>
	 */
	public function filterNull(): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->filter());
	}

	/**
	 * Remove all values which are not the given class
	 *
	 * @template TNewValue of object
	 *
	 * @param class-string<TNewValue> $class The class name to filter by
	 *
	 * @return self<TKey,TNewValue>
	 *
	 * @psalm-suppress InvalidReturnType,InvalidReturnStatement
	 */
	public function keepOnly(string $class): self {
		// @phpstan-ignore-next-line
		return new self(
			$this->collection->filter(
				static fn (mixed $value): bool => $value instanceof $class
			)
		);
	}

	/**
	 * Filter items by the given key value pair.
	 *
	 * @return self<TKey,TValue>
	 */
	public function where(string $key, mixed $operator=null, mixed $value=null): self {
		/**
		 * @psalm-suppress MixedArgument
		 *
		 * @phpstan-ignore-next-line
		 */
		return new self($this->collection->where(...func_get_args()));
	}

	/**
	 * Filter items where the value for the given key is null.
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereNull(string $key): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->whereNull($key));
	}

	/**
	 * Filter items where the value for the given key is not null.
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereNotNull(string $key): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->whereNotNull($key));
	}

	/**
	 * Filter items by the given key value pair.
	 *
	 * @param iterable<mixed> $values
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereIn(string $key, iterable $values, bool $strict=false): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->whereIn($key, $values, $strict));
	}

	/**
	 * Filter items by the given key value pair using strict comparison.
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereStrict(string $key, mixed $value): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->whereStrict($key, $value));
	}

	/**
	 * Return only unique items from the collection array.
	 *
	 * @param null|(callable(TValue,TKey): mixed)|string $key
	 *
	 * @return self<TKey,TValue>
	 */
	public function unique(mixed $key=null): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->unique($key));
	}

	// ─── Sorting ────────────────────────────────────────────────────────

	/**
	 * Sort through each item with a callback.
	 *
	 * @param callable(TValue, TValue): int $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function uasort(callable $callback): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->sort($callback));
	}

	/**
	 * Sort through each item on a given field
	 *
	 * @return self<TKey,TValue>
	 */
	public function asort(?string $field=null): self {
		if ($field === null) {
			// @phpstan-ignore-next-line
			return new self($this->collection->sort());
		}
		// @phpstan-ignore-next-line
		return new self($this->collection->sortBy($field));
	}

	/**
	 * Sort the collection using a field name with dot notation
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortBy(string $field, int $options=\SORT_REGULAR, bool $descending=false): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->sortBy($field, $options, $descending));
	}

	/**
	 * Sort the collection in descending order, using a field
	 * name with dot notation
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortByDesc(string $field, int $options=\SORT_REGULAR): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->sortByDesc($field, $options));
	}

	/**
	 * Sort the collection using the given callback to return
	 * a value for comparison
	 *
	 * @param callable(TValue,TKey):mixed $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortUsing(callable $callback, int $options=\SORT_REGULAR, bool $descending=false): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->sortBy($callback, $options, $descending));
	}

	/**
	 * Sort the collection in descending order,
	 * using the given callback to return a value
	 * for comparison
	 *
	 * @param callable(TValue,TKey):mixed $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortUsingDesc(callable $callback, int $options=\SORT_REGULAR): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->sortByDesc($callback, $options));
	}

	/**
	 * Sort the collection keys ascending.
	 *
	 * @return self<TKey,TValue>
	 */
	public function ksort(int $options=\SORT_REGULAR): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->sortKeys($options, false));
	}

	/**
	 * Sort the collection keys descending.
	 *
	 * @return self<TKey,TValue>
	 */
	public function krsort(int $options=\SORT_REGULAR): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->sortKeys($options, true));
	}

	// ─── Grouping ─────────────────────────────────────────────────────

	/**
	 * Group an associative array by a field or using a callback.
	 *
	 * @param (callable(TValue,TKey): array-key)|string $groupBy
	 *
	 * @return self<array-key,self<array-key, TValue>>
	 *
	 * @phpstan-ignore-next-line
	 */
	public function groupBy(callable|string $groupBy): self {
		$grouped = $this->collection->groupBy($groupBy, false);

		$result = new self($grouped->map(static fn (SupportCollection $group): self => new self($group)));
		return $result;
	}

	/**
	 * Group an associative array by a string field or using a callback.
	 *
	 * @param (callable(TValue,TKey):string)|string[]|string $groupBy
	 *
	 * @return static<string,static<int,TValue>>
	 */
	public function groupByString(callable|array|string $groupBy): self {
		/**
		 * @var SupportCollection<string,SupportCollection<int,TValue>>
		 *
		 * @phpstan-ignore-next-line
		 */
		$grouped = $this->collection->groupBy($groupBy, false);

		/** @var static<string,static<int,TValue>> */
		$result = new self($grouped->map(static fn (SupportCollection $group): self => new self($group)));
		return $result;
	}

	/**
	 * Group an associative array by an int field or using a callback.
	 *
	 * @param (callable(TValue,TKey):int)|string[]|string $groupBy
	 *
	 * @return static<int,static<int,TValue>>
	 */
	public function groupByInt(callable|array|string $groupBy): self {
		/**
		 * @var SupportCollection<int,SupportCollection<int,TValue>>
		 *
		 * @phpstan-ignore-next-line
		 */
		$grouped = $this->collection->groupBy($groupBy, false);

		/** @var static<int,static<int,TValue>> */
		$result = new self($grouped->map(static fn (SupportCollection $group): self => new self($group)));
		return $result;
	}

	// ─── Keying ─────────────────────────────────────────────────────────

	/** @return self<array-key,TValue> */
	public function keyBy(string $keyBy): self {
		return new self($this->collection->keyBy($keyBy));
	}

	/**
	 * Key an associative array by using a callback.
	 *
	 * @template TKeyBy of array-key
	 *
	 * @param callable(TValue,TKey):TKeyBy $keyBy
	 *
	 * @return self<TKeyBy,TValue>
	 */
	public function keyByUsing(callable $keyBy): self {
		return new self($this->collection->keyBy($keyBy));
	}

	/**
	 * @return self<int,TValue>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function keyByInt(string $keyBy): self {
		return new self($this->collection->keyBy($keyBy));
	}

	/**
	 * @return self<string,TValue>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function keyByString(string $keyBy): self {
		return new self($this->collection->keyBy($keyBy));
	}

	// ─── Aggregation ──────────────────────────────────────────────────

	/**
	 * Get the sum of the given values.
	 *
	 * @param null|(callable(TValue): mixed)|string $callback
	 */
	public function sum(null|callable|string $callback=null): int|float {
		$result = $this->collection->sum($callback);
		if (is_int($result)) {
			return $result;
		}
		return (float)$result;
	}

	/**
	 * Get the min value of a given key.
	 *
	 * @param null|(callable(TValue):mixed)|string $callback
	 */
	public function min(null|callable|string $callback=null): mixed {
		return $this->collection->min($callback);
	}

	/**
	 * Get the max value of a given key.
	 *
	 * @param null|(callable(TValue):mixed)|string $callback
	 */
	public function max(null|callable|string $callback=null): mixed {
		return $this->collection->max($callback);
	}

	/**
	 * Get the average value of a given key.
	 *
	 * @param null|(callable(TValue): (float|int))|string $callback
	 */
	public function avg(null|callable|string $callback=null): null|float|int {
		return $this->collection->avg($callback);
	}

	/**
	 * Reduce the collection to a single value.
	 *
	 * @template TReduceInitial
	 * @template TReduceReturnType
	 *
	 * @param callable(TReduceInitial|TReduceReturnType,TValue,TKey):TReduceReturnType $callback
	 * @param TReduceInitial                                                           $initial
	 *
	 * @return TReduceReturnType
	 */
	public function reduce(callable $callback, mixed $initial=null): mixed {
		return $this->collection->reduce($callback, $initial);
	}

	/**
	 * Count the number of items in the collection by using a callback.
	 *
	 * @template TCountByValue of array-key
	 *
	 * @param callable(TValue,TKey):TCountByValue $callback
	 *
	 * @return self<TCountByValue,int>
	 */
	public function countByUsing(callable $callback): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->countBy($callback));
	}

	// ─── Plucking ───────────────────────────────────────────────────────

	/**
	 * Get the values of a given key.
	 *
	 * @return self<array-key,mixed>
	 */
	public function pluck(string $value, ?string $key=null): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->pluck($value, $key));
	}

	/**
	 * Get the int values of a given key
	 *
	 * @param string|int|array<array-key,string> $value
	 *
	 * @return self<array-key,int>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function pluckInts(string|int|array $value, ?string $key=null): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->pluck($value, $key));
	}

	/**
	 * Get the string values of a given key
	 *
	 * @param string|int|array<array-key, string> $value
	 *
	 * @return self<array-key,string>
	 *
	 * @psalm-suppress MixedReturnTypeCoercion
	 */
	public function pluckStrings(string|int|array $value, ?string $key=null): self {
		// @phpstan-ignore-next-line
		return new self($this->collection->pluck($value, $key));
	}

	// ─── Iteration ──────────────────────────────────────────────────────

	/**
	 * Get an iterator for the items.
	 *
	 * @return \Traversable<TKey,TValue>
	 */
	public function getIterator(): \Traversable {
		return $this->collection->getIterator();
	}

	/**
	 * Executes a callback over each entry until FALSE is returned.
	 *
	 * The $result array will contain [0 => 'A'] because FALSE is returned
	 * after the first entry and all other entries are then skipped.
	 *
	 * @param callable(TValue,TKey):mixed $callback Function to run iver all elements
	 *
	 * @return $this
	 */
	public function each(callable $callback): self {
		$this->collection->each($callback);
		return $this;
	}

	// ─── Random ─────────────────────────────────────────────────────────

	/**
	 * Get one item randomly from the collection.
	 *
	 * @return TValue
	 *
	 * @psalm-suppress InvalidReturnType
	 */
	public function pickRandom(): mixed {
		/**
		 * @psalm-suppress InvalidReturnStatement
		 *
		 * @phpstan-ignore-next-line
		 */
		return $this->collection->random();
	}

	/**
	 * Convert the object into something JSON serializable.
	 *
	 * @return array<TKey, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->collection->jsonSerialize();
	}

	/** Recursively convert a value to an array. */
	private static function convertToArray(mixed $value): mixed {
		if ($value instanceof self) {
			return $value->toArray();
		}
		if (is_array($value)) {
			return array_map(self::convertToArray(...), $value);
		}
		if (is_iterable($value)) {
			$result = [];
			foreach ($value as $key => $item) {
				/** @var array-key $key */
				$result[$key] = self::convertToArray($item);
			}
			return $result;
		}
		return $value;
	}
}
