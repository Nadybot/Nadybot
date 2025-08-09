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
}
