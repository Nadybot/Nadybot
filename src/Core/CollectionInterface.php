<?php declare(strict_types=1);

namespace Nadybot\Core;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Nadybot\Core\Exceptions\ItemNotFoundException;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends IteratorAggregate<TKey,TValue>
 * @extends ArrayAccess<TKey,TValue>
 */
interface CollectionInterface extends Countable, IteratorAggregate, ArrayAccess {
	/**
	 * Key an associative array by a field
	 *
	 * @return self<array-key,TValue>
	 */
	public function keyBy(string $keyBy): self;

	/**
	 * Key an associative array by using a callback.
	 *
	 * @template TKeyBy of array-key
	 *
	 * @param callable(TValue,TKey):TKeyBy $keyBy
	 *
	 * @return self<TKeyBy,TValue>
	 */
	public function keyByUsing(callable $keyBy): self;

	/**
	 * Key the items of the collection by an integer field
	 *
	 * @return self<int,TValue>
	 */
	public function keyByInt(string $keyBy): self;

	/**
	 * Key the items of the collection by a string field
	 *
	 * @return self<string,TValue>
	 */
	public function keyByString(string $keyBy): self;

	/**
	 * Get the last item of a collection
	 *
	 * @return TValue
	 *
	 * @throws ItemNotFoundException if the item does not exist
	 */
	public function lastOrFail(): mixed;

	/**
	 * Get the first item in the collection but throw an exception if no matching items exist.
	 *
	 * @param null|(callable(TValue, TKey): bool)|string $key
	 *
	 * @return TValue
	 *
	 * @throws ItemNotFoundException
	 */
	public function firstOrFail(null|callable|string $key=null, mixed $operator=null, mixed $value=null): mixed;

	/**
	 * Get the values of a given key.
	 *
	 * @return self<array-key,mixed>
	 */
	public function pluck(string $value, ?string $key=null): self;

	/**
	 * Get the int values of a given key
	 *
	 * @return self<array-key,int>
	 */
	public function pluckInts(string $value, ?string $key=null): self;

	/**
	 * Get the string values of a given key
	 *
	 * @return self<array-key,string>
	 */
	public function pluckStrings(string $value, ?string $key=null): self;

	/**
	 * Get the collection of items as a plain list.
	 *
	 * @return list<TValue>
	 */
	public function toList(): array;

	/**
	 * Get the collection of items as a plain array.
	 *
	 * @return array<TKey,TValue>
	 */
	public function toArray(): array;

	/**
	 * Group an associative array by a string field or using a callback.
	 *
	 * @param (callable(TValue,TKey):string)|string[]|string $groupBy
	 *
	 * @return static<string,static<int,TValue>>
	 */
	public function groupByString(callable|array|string $groupBy): self;

	/**
	 * Group an associative array by an int field or using a callback.
	 *
	 * @param (callable(TValue,TKey):int)|string[]|string $groupBy
	 *
	 * @return static<int,static<int,TValue>>
	 */
	public function groupByInt(callable|array|string $groupBy): self;

	/**
	 * Push one or more items onto the end of the collection.
	 *
	 * @param TValue ...$values
	 *
	 * @return $this
	 */
	public function push(mixed ...$values): self;

	/**
	 * Get and remove the last item from the collection.
	 *
	 * @return null|TValue
	 */
	public function pop(): mixed;

	/**
	 * Put an item in the collection by key.
	 *
	 * @param TKey   $key
	 * @param TValue $value
	 *
	 * @return $this
	 */
	public function put($key, $value): self;

	/**
	 * Remove an item from the collection by key.
	 *
	 * @param iterable<string|int>|string|int $keys List of keys to remove
	 *
	 * @return $this
	 */
	public function remove(iterable|string|int $keys): self;

	/**
	 * Get and remove the first item from the collection.
	 *
	 * @return ?TValue
	 */
	public function shift(): mixed;

	/**
	 * Filter items by the given key value pair.
	 *
	 * @return self<TKey,TValue>
	 */
	public function where(string $key, mixed $operator=null, mixed $value=null): self;

	/**
	 * Sort through each item with a callback.
	 *
	 * @param callable(TValue, TValue): int $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function uasort(callable $callback): self;

	/**
	 * Sort through each item on a given field
	 *
	 * @return self<TKey,TValue>
	 */
	public function asort(?string $field=null): self;

	/**
	 * Sort the collection using a field name with dot notation
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortBy(string $field, int $options=\SORT_REGULAR, bool $descending=false): self;

	/**
	 * Sort the collection using the given callback to return
	 * a value for comparison
	 *
	 * @param callable(TValue, TKey):mixed $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortUsing(callable $callback, int $options=\SORT_REGULAR, bool $descending=false): self;

	/**
	 * Sort the collection in descending order, using a field
	 * name with dot notation
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortByDesc(string $field, int $options=\SORT_REGULAR): self;

	/**
	 * Sort the collection in descending order,
	 * using the given callback to return a value
	 * for comparison
	 *
	 * @param callable(TValue, TKey):mixed $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function sortUsingDesc(callable $callback, int $options=\SORT_REGULAR): self;

	/**
	 * Filter items where the value for the given key is null.
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereNull(string $key): self;

	/**
	 * Filter items where the value for the given key is not null.
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereNotNull(string $key): self;

	/**
	 * Filter items by the given key value pair.
	 *
	 * @param iterable<mixed> $values
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereIn(string $key, iterable $values, bool $strict=false): self;

	/**
	 * Filter items by the given key value pair using strict comparison.
	 *
	 * @return self<TKey,TValue>
	 */
	public function whereStrict(string $key, mixed $value): self;

	/**
	 * Run a filter over each of the items.
	 *
	 * @param callable(TValue,TKey):bool $callback
	 *
	 * @return self<TKey,TValue>
	 */
	public function filter(callable $callback);

	/**
	 * Remove all null values from the collection.
	 *
	 * @return self<TKey,TValue>
	 *
	 * @phpstan-return self<TKey, (TValue is null ? never : TValue)>
	 *
	 * @psalm-return self<TKey, (TValue is null ? never : TValue)>
	 */
	public function filterNull(): self;

	/**
	 * Return only unique items from the collection array.
	 *
	 * @param null|(callable(TValue, TKey): mixed)|string $key
	 *
	 * @return self<TKey,TValue>
	 */
	public function unique(mixed $key=null): self;

	/**
	 * Reverse items order.
	 *
	 * @return self<TKey,TValue>
	 */
	public function reverse(): self;

	/**
	 * Sort the collection keys ascending.
	 *
	 * @return self<TKey,TValue>
	 */
	public function ksort(int $options=\SORT_REGULAR): self;

	/**
	 * Sort the collection keys descending.
	 *
	 * @return self<TKey,TValue>
	 */
	public function krsort(int $options=\SORT_REGULAR): self;

	/** Check if the collection is empty. */
	public function isEmpty(): bool;

	/** Check if the collection is not empty. */
	public function isNotEmpty(): bool;

	/**
	 * Get an iterator for the items.
	 *
	 * @return \Traversable<TKey,TValue>
	 */
	public function getIterator(): \Traversable;

	/**
	 * Executes a callback over each entry until FALSE is returned.
	 *
	 * The $result array will contain [0 => 'A'] because FALSE is returned
	 * after the first entry and all other entries are then skipped.
	 *
	 * @param callable(TValue,TKey):mixed $callback Function to run iver all elements
	 *
	 * @return self<int|string,mixed> Same map for fluid interface
	 */
	public function each(callable $callback): self;

	/**
	 * Get the items in the collection that are not present in the given items.
	 *
	 * @param iterable<array-key,TValue> $items
	 *
	 * @return self<TKey,TValue>
	 */
	public function diff(iterable $items): self;

	/**
	 * Run a map over each of the items.
	 *
	 * @template TMapValue
	 *
	 * @param callable(TValue, TKey): TMapValue $callback
	 *
	 * @return self<TKey, TMapValue>
	 */
	public function map(callable $callback): self;

	/**
	 * Group an associative array by a field or using a callback.
	 *
	 * @param (callable(TValue, TKey): array-key)|string $groupBy
	 *
	 * @return self<array-key,self<array-key, TValue>>
	 */
	public function groupBy(callable|string $groupBy): self;

	/**
	 * Get an item from the collection by key.
	 *
	 * @template TGetDefault
	 *
	 * @param TKey                                  $key
	 * @param TGetDefault|(\Closure(): TGetDefault) $default
	 *
	 * @return TValue|TGetDefault
	 */
	public function get(string|int $key, mixed $default=null): mixed;

	/** Join all items from the collection using a string. The final items can use a separate glue string. */
	public function join(string $glue, string $finalGlue=''): string;

	/**
	 * Slice the underlying collection array.
	 *
	 * @return self<TKey,TValue>
	 */
	public function slice(int $offset, ?int $length=null): self;

	/**
	 * Merge the collection with the given items.
	 *
	 * @param iterable<TKey,TValue> $items
	 *
	 * @return self<TKey,TValue>
	 */
	public function merge(iterable $items): self;

	/**
	 * Determine if an item exists in the collection.
	 *
	 * @param (callable(TValue,TKey):bool)|TValue|string $key
	 */
	public function contains(mixed $key, mixed $operator=null, mixed $value=null): bool;

	/**
	 * Determine if an item exists, using strict comparison.
	 *
	 * @param (callable(TValue):bool)|TValue|array-key $key
	 * @param null|TValue                              $value
	 */
	public function containsStrict(mixed $key, mixed $value=null): bool;

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
	public function reduce(callable $callback, mixed $initial=null): mixed;

	/**
	 * Get the last item from the collection.
	 *
	 * @template TLastDefault
	 *
	 * @param null|(callable(TValue, TKey): bool)    $callback
	 * @param TLastDefault|(\Closure():TLastDefault) $default
	 *
	 * @return TValue|TLastDefault
	 */
	public function last(?callable $callback=null, mixed $default=null): mixed;

	/**
	 * Get the first item from the collection passing the given truth test.
	 *
	 * @template TFirstDefault
	 *
	 * @param null|(callable(TValue,TKey):bool)        $callback
	 * @param TFirstDefault|(\Closure():TFirstDefault) $default
	 *
	 * @return TValue|TFirstDefault
	 */
	public function first(?callable $callback=null, $default=null): mixed;

	/**
	 * Get the keys of the collection items.
	 *
	 * @return self<int,TKey>
	 */
	public function keys(): self;

	/**
	 * Reset the keys on the underlying array.
	 *
	 * @return self<int,TValue>
	 */
	public function values(): self;

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
	public function concat(iterable $source): self;

	/**
	 * Determine if an item exists in the collection by key.
	 *
	 * @param TKey $key
	 */
	public function has(int|string $key): bool;

	/**
	 * Get the sum of the given values.
	 *
	 * @param null|(callable(TValue): mixed)|string $callback
	 */
	public function sum(null|callable|string $callback=null): int|float;

	/**
	 * Get the first item by the given key value pair.
	 *
	 * @return null|TValue
	 */
	public function firstWhere(callable|string $key, mixed $operator=null, mixed $value=null): mixed;

	/**
	 * Get a flattened array of the items in the collection.
	 *
	 * @return self<int,mixed>
	 */
	public function flatten(int $depth=\PHP_INT_MAX): self;

	/** Determine if the collection contains a single item. */
	public function containsOneItem(): bool;

	/**
	 * Get the min value of a given key.
	 *
	 * @param null|(callable(TValue):mixed)|string $callback
	 */
	public function min(null|callable|string $callback=null): mixed;

	/**
	 * Get the max value of a given key.
	 *
	 * @param null|(callable(TValue):mixed)|string $callback
	 */
	public function max(null|callable|string $callback=null): mixed;

	/**
	 * Get the average value of a given key.
	 *
	 * @param null|(callable(TValue): (float|int))|string $callback
	 */
	public function avg(null|callable|string $callback=null): null|float|int;

	/**
	 * Get one item randomly from the collection.
	 *
	 * @return TValue
	 */
	public function pickRandom(): mixed;

	/**
	 * Count the number of items in the collection by using a callback.
	 *
	 * @template TCountByValue of array-key
	 *
	 * @param callable(TValue,TKey):TCountByValue $callback
	 *
	 * @return self<TCountByValue,int>
	 */
	public function countByUsing(callable $callback): self;

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
	public function mapToDictionary(callable $callback): self;
}
