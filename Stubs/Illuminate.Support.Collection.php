<?php declare(strict_types=1);

namespace Illuminate\Support;

use ArrayAccess;
use Illuminate\Contracts\Support\CanBeEscapedWhenCastToString;

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @implements \ArrayAccess<TKey, TValue>
 * @implements \Illuminate\Support\Enumerable<TKey, TValue>
 *
 * @method static<TKey,TValue> notNull()
 *
 * @phpstan-ignore-next-line
 */
class Collection implements ArrayAccess, CanBeEscapedWhenCastToString, Enumerable {
	/**
	 * Filter items by the given key value pair.
	 *
	 * @return static<TKey, TValue>
	 */
	public function where(callable|string $key, mixed $operator=null, mixed $value=null) {
	}

	/**
	 * Filter items where the value for the given key is null.
	 *
	 * @return static<TKey, TValue>
	 */
	public function whereNull(?string $key=null) {
	}

	/**
	 * Filter items by the given key value pair using strict comparison.
	 *
	 * @return static<TKey, TValue>
	 */
	public function whereStrict(string $key, mixed $value) {
	}

	/**
	 * Run a filter over each of the items.
	 *
	 * @param null|(callable(TValue, TKey): bool) $callback
	 *
	 * @return static<TKey, TValue>
	 */
	public function filter(?callable $callback=null) {
	}

	/**
	 * Sort through each item with a callback.
	 *
	 * @param null|(callable(TValue, TValue): int)|int $callback
	 *
	 * @return static<TKey, TValue>
	 */
	public function sort($callback=null) {
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
	public function sortBy($callback, $options=\SORT_REGULAR, $descending=false) {
	}

	/**
	 * Sort the collection in descending order using the given callback.
	 *
	 * @param array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string $callback
	 * @param int                                                                                                                                                     $options
	 *
	 * @return static<TKey, TValue>
	 */
	public function sortByDesc($callback, $options=\SORT_REGULAR) {
	}

	/**
	 * Return only unique items from the collection array.
	 *
	 * @param null|(callable(TValue, TKey): mixed)|string $key
	 * @param bool                                        $strict
	 *
	 * @return static<TKey, TValue>
	 */
	public function unique($key=null, $strict=false) {
	}

	/**
	 * Filter items by the given key value pair.
	 *
	 * @param \Illuminate\Contracts\Support\Arrayable<array-key,mixed>|iterable<array-key,mixed> $values
	 *
	 * @return static<TKey, TValue>
	 */
	public function whereIn(string $key, mixed $values, bool $strict=false) {
	}

	/**
	 * Sort the collection keys.
	 *
	 * @param int  $options
	 * @param bool $descending
	 *
	 * @return static<TKey, TValue>
	 */
	public function sortKeys($options=\SORT_REGULAR, $descending=false) {
	}

	/**
	 * Reverse items order.
	 *
	 * @return static<TKey, TValue>
	 */
	public function reverse() {
	}
}
