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
 * @method static<TKey,TValue>      notNull()
 * @method list<TValue>             toList()
 * @method TValue                   lastOrFail()
 * @method static<array-key,int>    pluckInts(string|int|array<array-key,string> $value, ?string $key=null)
 * @method static<array-key,string> pluckStrings(string|int|array<array-key,string> $value, ?string $key=null)
 *
 * @phpstan-ignore-next-line
 */
class Collection implements ArrayAccess, CanBeEscapedWhenCastToString, Enumerable {
	/**
	 * Get the collection of items as a plain array.
	 *
	 * @return array<TKey, TValue>
	 */
	public function toArray(): array {
	}

	/**
	 * Get the last item of a collection
	 *
	 * @return TValue
	 *
	 * @throws \Illuminate\Support\ItemNotFoundException if the item does not exist
	 */
	public function lastOrFail(): mixed {
	}

	/**
	 * Get the collection of items as a plain list.
	 *
	 * @return list<TValue>
	 */
	public function toList(): array {
	}

	/**
	 * Get the int values of a given key
	 *
	 * @param string|int|array<array-key, string> $value
	 *
	 * @return static<array-key, int>
	 */
	public function pluckInts(string|int|array $value, ?string $key=null): static {
	}

	/**
	 * Get the string values of a given key
	 *
	 * @param string|int|array<array-key, string> $value
	 *
	 * @return static<array-key, string>
	 */
	public function pluckStrings(string|int|array $value, ?string $key=null): static {
	}

	/**
	 * Remove all null value
	 *
	 * @psalm-assert !null TValue
	 *
	 * @return static<TKey, TValue>
	 */
	public function notNull(): static {
	}

	/**
	 * Group an associative array by a field or using a callback.
	 *
	 * @param (callable(TValue, TKey): array-key)|string[]|string $groupBy
	 * @param bool                                                $preserveKeys
	 *
	 * @return static<array-key, static<int, TValue>>
	 */
	public function groupBy($groupBy, $preserveKeys=false): static {
	}
}
