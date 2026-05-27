<?php declare(strict_types=1);

namespace Nadybot\Tests\Core;

use Nadybot\Core\Collection;
use Nadybot\Core\Exceptions\ItemNotFoundException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(Collection::class)]
class CollectionTest extends TestCase {
	// ─── Construction ─────────────────────────────────────────────────

	#[Test]
	public function canBeConstructedFromArray(): void {
		$collection = new Collection([1, 2, 3]);
		$this->assertSame([1, 2, 3], $collection->toArray());
	}

	#[Test]
	public function canBeConstructedFromAnotherCollectionInterface(): void {
		$first = new Collection(['a', 'b', 'c']);
		$second = new Collection($first);
		$this->assertSame(['a', 'b', 'c'], $second->toArray());
	}

	#[Test]
	public function makeFactoryReturnsNewInstance(): void {
		$collection = Collection::make([1, 2, 3]);
		$this->assertInstanceOf(Collection::class, $collection);
		$this->assertSame([1, 2, 3], $collection->toArray());
	}

	// ─── Basic Access ─────────────────────────────────────────────────

	#[Test]
	public function getReturnsValueByKey(): void {
		$collection = new Collection(['name' => 'Nady', 'age' => 30]);
		$this->assertSame('Nady', $collection->get('name'));
		$this->assertSame(30, $collection->get('age'));
	}

	#[Test]
	public function getReturnsDefaultForMissingKey(): void {
		$collection = new Collection(['name' => 'Nady']);
		$this->assertNull($collection->get('missing'));
		$this->assertSame('default', $collection->get('missing', 'default'));
	}

	#[Test]
	public function hasChecksKeyExistence(): void {
		$collection = new Collection(['name' => 'Nady']);
		$this->assertTrue($collection->has('name'));
		$this->assertFalse($collection->has('missing'));
	}

	#[Test]
	public function firstReturnsFirstItem(): void {
		$collection = new Collection([10, 20, 30]);
		$this->assertSame(10, $collection->first());
	}

	#[Test]
	public function firstReturnsDefaultWhenEmpty(): void {
		$collection = new Collection([]);
		$this->assertNull($collection->first());
		$this->assertSame('default', $collection->first(default: 'default'));
	}

	#[Test]
	public function firstWithCallbackFiltersItems(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$result = $collection->first(static fn (int $v): bool => $v > 3);
		$this->assertSame(4, $result);
	}

	#[Test]
	public function lastReturnsLastItem(): void {
		$collection = new Collection([10, 20, 30]);
		$this->assertSame(30, $collection->last());
	}

	#[Test]
	public function lastWithCallbackFiltersItems(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$result = $collection->last(static fn (int $v): bool => $v < 4);
		$this->assertSame(3, $result);
	}

	#[Test]
	public function lastReturnsDefaultWhenEmpty(): void {
		$collection = new Collection([]);
		$this->assertNull($collection->last());
		$this->assertSame('default', $collection->last(null, 'default'));
	}

	#[Test]
	public function firstOrFailThrowsWhenEmpty(): void {
		$collection = new Collection([]);
		$this->expectException(ItemNotFoundException::class);
		$collection->firstOrFail();
	}

	#[Test]
	public function firstOrFailReturnsItemWhenNotEmpty(): void {
		$collection = new Collection([42]);
		$this->assertSame(42, $collection->firstOrFail());
	}

	#[Test]
	public function firstOrFailWithCallableFiltersItems(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$this->assertSame(4, $collection->firstOrFail(static fn (int $v): bool => $v > 3));
	}

	#[Test]
	public function firstOrFailWithStringKeyAndOperator(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$this->assertSame(['name' => 'Bob', 'age' => 30], $collection->firstOrFail('age', '>=', 30));
	}

	#[Test]
	public function lastOrFailThrowsWhenEmpty(): void {
		$collection = new Collection([]);
		$this->expectException(ItemNotFoundException::class);
		$collection->lastOrFail();
	}

	#[Test]
	public function lastOrFailReturnsItemWhenNotEmpty(): void {
		$collection = new Collection([10, 20, 30]);
		$this->assertSame(30, $collection->lastOrFail());
	}

	#[Test]
	public function firstWhereFindsMatchingItem(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$result = $collection->firstWhere('age', 30);
		$this->assertSame(['name' => 'Bob', 'age' => 30], $result);
	}

	#[Test]
	public function firstWhereWithCallable(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$result = $collection->firstWhere(static fn (int $v): bool => $v > 3);
		$this->assertSame(4, $result);
	}

	// ─── ArrayAccess ────────────────────────────────────────────────────

	#[Test]
	public function offsetExistsChecksPresence(): void {
		$collection = new Collection(['a', 'b']);
		$this->assertTrue($collection->offsetExists(0));
		$this->assertFalse($collection->offsetExists(99));
	}

	#[Test]
	public function offsetGetRetrievesValue(): void {
		$collection = new Collection(['a', 'b']);
		$this->assertSame('a', $collection->offsetGet(0));
	}

	#[Test]
	public function offsetSetAddsValue(): void {
		$collection = new Collection(['a']);
		$collection->offsetSet(1, 'b');
		$this->assertSame(['a', 'b'], $collection->toArray());
	}

	#[Test]
	public function offsetUnsetRemovesValue(): void {
		$collection = new Collection(['a', 'b', 'c']);
		$collection->offsetUnset(1);
		$this->assertSame([0 => 'a', 2 => 'c'], $collection->toArray());
	}

	// ─── Modification ───────────────────────────────────────────────────

	#[Test]
	public function pushAddsItems(): void {
		$collection = new Collection([1, 2]);
		$result = $collection->push(3, 4);
		$this->assertSame([1, 2, 3, 4], $collection->toArray());
		$this->assertSame($collection, $result); // fluent interface
	}

	#[Test]
	public function putSetsValueByKey(): void {
		$collection = new Collection([]);
		$result = $collection->put('key', 'value');
		$this->assertSame(['key' => 'value'], $collection->toArray());
		$this->assertSame($collection, $result);
	}

	#[Test]
	public function popRemovesAndReturnsLastItem(): void {
		$collection = new Collection([1, 2, 3]);
		$this->assertSame(3, $collection->pop());
		$this->assertSame([1, 2], $collection->toArray());
	}

	#[Test]
	public function popReturnsNullWhenEmpty(): void {
		$collection = new Collection([]);
		$this->assertNull($collection->pop());
	}

	#[Test]
	public function shiftRemovesAndReturnsFirstItem(): void {
		$collection = new Collection([1, 2, 3]);
		$this->assertSame(1, $collection->shift());
		$this->assertSame([2, 3], $collection->toArray());
	}

	#[Test]
	public function shiftReturnsNullWhenEmpty(): void {
		$collection = new Collection([]);
		$this->assertNull($collection->shift());
	}

	#[Test]
	public function removeDeletesByKey(): void {
		$collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
		$result = $collection->remove('b');
		$this->assertSame(['a' => 1, 'c' => 3], $collection->toArray());
		$this->assertSame($collection, $result);
	}

	#[Test]
	public function removeDeletesMultipleKeys(): void {
		$collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]);
		$collection->remove(['a', 'c']);
		$this->assertSame(['b' => 2, 'd' => 4], $collection->toArray());
	}

	#[Test]
	public function removeDeletesIntKey(): void {
		$collection = new Collection([10, 20, 30]);
		$collection->remove(1);
		$this->assertSame([0 => 10, 2 => 30], $collection->toArray());
	}

	// ─── Filtering ──────────────────────────────────────────────────────
	#[Test]
	public function filterNullRemovesNullValues(): void {
		$collection = new Collection([1, 2, null, 3, 4, 5]);
		$filtered = $collection->filterNull();
		$this->assertSame([1, 2, 3, 4, 5], $filtered->toList());
	}

	#[Test]
	public function filterWithCallback(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$filtered = $collection->filter(static fn (int $v): bool => $v > 3);
		$this->assertSame([3 => 4, 4 => 5], $filtered->toArray());
	}

	#[Test]
	public function filterWithTruthyCallbackRemovesFalsyValues(): void {
		$collection = new Collection([1, 0, 2, null, 3, false, 4, '']);
		$filtered = $collection->filter(static fn (mixed $v): bool => (bool)$v);
		$this->assertSame([0 => 1, 2 => 2, 4 => 3, 6 => 4], $filtered->toArray());
	}

	#[Test]
	public function whereFiltersByKeyValue(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
			['name' => 'Charlie', 'age' => 25],
		]);
		$filtered = $collection->where('age', 25);
		$this->assertCount(2, $filtered);
	}

	#[Test]
	public function whereWithOperator(): void {
		$collection = new Collection([
			['value' => 1],
			['value' => 2],
			['value' => 3],
			['value' => 4],
			['value' => 5],
		]);
		$filtered = $collection->where('value', '>', 3);
		$this->assertSame([
			['value' => 4],
			['value' => 5],
		], $filtered->values()->toArray());
	}

	#[Test]
	public function whereNullFiltersNullValues(): void {
		$collection = new Collection([
			['name' => 'Alice', 'nickname' => null],
			['name' => 'Bob', 'nickname' => 'Bobby'],
		]);
		$filtered = $collection->whereNull('nickname');
		$this->assertCount(1, $filtered);
		$this->assertSame('Alice', $filtered->first()['name']);
	}

	#[Test]
	public function whereNotNullFiltersNonNullValues(): void {
		$collection = new Collection([
			['name' => 'Alice', 'nickname' => null],
			['name' => 'Bob', 'nickname' => 'Bobby'],
		]);
		$filtered = $collection->whereNotNull('nickname');
		$this->assertCount(1, $filtered);
		$this->assertSame('Bob', $filtered->first()['name']);
	}

	#[Test]
	public function whereInFiltersByValues(): void {
		$collection = new Collection([
			['value' => 1],
			['value' => 2],
			['value' => 3],
			['value' => 4],
			['value' => 5],
		]);
		$filtered = $collection->whereIn('value', [2, 4]);
		$this->assertSame([
			['value' => 2],
			['value' => 4],
		], $filtered->values()->toArray());
	}

	#[Test]
	public function whereInWithStrictComparison(): void {
		$collection = new Collection([
			['value' => 1],
			['value' => '2'],
			['value' => 3],
		]);
		$filtered = $collection->whereIn('value', [2, 3], true);
		$this->assertSame([
			['value' => 3],
		], $filtered->values()->toArray());
	}

	#[Test]
	public function whereStrictFiltersByStrictComparison(): void {
		$collection = new Collection([
			['value' => 1],
			['value' => '1'],
			['value' => 2],
		]);
		$filtered = $collection->whereStrict('value', 1);
		$this->assertSame([['value' => 1]], $filtered->values()->toArray());
	}

	#[Test]
	public function uniqueRemovesDuplicates(): void {
		$collection = new Collection([1, 2, 2, 3, 3, 3]);
		$unique = $collection->unique();
		$this->assertSame([1, 2, 3], $unique->values()->toArray());
	}

	#[Test]
	public function uniqueByKey(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
			['name' => 'Charlie', 'age' => 25],
		]);
		$unique = $collection->unique('age');
		$this->assertCount(2, $unique);
	}

	#[Test]
	public function uniqueWithCallable(): void {
		$collection = new Collection([1, 2, 3, 4, 5, 6]);
		$unique = $collection->unique(static fn (int $v): int => $v % 2);
		$this->assertCount(2, $unique);
	}

	// ─── Transformation ───────────────────────────────────────────────

	#[Test]
	public function mapTransformsValues(): void {
		$collection = new Collection([1, 2, 3]);
		$mapped = $collection->map(static fn (int $v): int => $v * 2);
		$this->assertSame([2, 4, 6], $mapped->toArray());
	}

	#[Test]
	public function mapPreservesKeys(): void {
		$collection = new Collection(['a' => 1, 'b' => 2]);
		$mapped = $collection->map(static fn (int $v): int => $v * 10);
		$this->assertSame(['a' => 10, 'b' => 20], $mapped->toArray());
	}

	#[Test]
	public function pluckExtractsValues(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$names = $collection->pluck('name');
		$this->assertSame(['Alice', 'Bob'], $names->values()->toArray());
	}

	#[Test]
	public function pluckWithKey(): void {
		$collection = new Collection([
			['id' => 1, 'name' => 'Alice'],
			['id' => 2, 'name' => 'Bob'],
		]);
		$mapped = $collection->pluck('name', 'id');
		$this->assertSame([1 => 'Alice', 2 => 'Bob'], $mapped->toArray());
	}

	#[Test]
	public function pluckIntsExtractsIntValues(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$ages = $collection->pluckInts('age');
		$this->assertSame([25, 30], $ages->values()->toArray());
	}

	#[Test]
	public function pluckIntsWithIntKey(): void {
		$collection = new Collection([
			[0 => 25],
			[0 => 30],
		]);
		$ages = $collection->pluckInts(0);
		$this->assertSame([25, 30], $ages->values()->toArray());
	}

	#[Test]
	public function pluckStringsExtractsStringValues(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$names = $collection->pluckStrings('name');
		$this->assertSame(['Alice', 'Bob'], $names->values()->toArray());
	}

	#[Test]
	public function pluckStringsWithIntKey(): void {
		$collection = new Collection([
			[0 => 'Alice'],
			[0 => 'Bob'],
		]);
		$names = $collection->pluckStrings(0);
		$this->assertSame(['Alice', 'Bob'], $names->values()->toArray());
	}

	#[Test]
	public function flattenFlattensNestedArrays(): void {
		$collection = new Collection([[1, 2], [3, 4], [5]]);
		$flattened = $collection->flatten();
		$this->assertSame([1, 2, 3, 4, 5], $flattened->toArray());
	}

	#[Test]
	public function flattenFlattensNestedCollections(): void {
		$collection = new Collection([
			Collection::make([1, 2]),
			Collection::make([3, 4]),
			Collection::make([5]),
		]);
		$flattened = $collection->flatten();
		$this->assertSame([1, 2, 3, 4, 5], $flattened->toArray());
	}

	#[Test]
	public function flattenWithDepth(): void {
		$collection = new Collection([[[1]], [[2]]]);
		$flattened = $collection->flatten(1);
		$this->assertSame([[1], [2]], $flattened->toArray());
	}

	#[Test]
	public function valuesResetsKeys(): void {
		$collection = new Collection(['a' => 1, 'b' => 2]);
		$values = $collection->values();
		$this->assertSame([1, 2], $values->toArray());
	}

	#[Test]
	public function keysReturnsCollectionKeys(): void {
		$collection = new Collection(['a' => 1, 'b' => 2]);
		$keys = $collection->keys();
		$this->assertSame(['a', 'b'], $keys->toArray());
	}

	#[Test]
	public function reverseReversesOrder(): void {
		$collection = new Collection([1, 2, 3]);
		$reversed = $collection->reverse();
		$this->assertSame([2 => 3, 1 => 2, 0 => 1], $reversed->toArray());
	}

	// ─── Sorting ────────────────────────────────────────────────────────

	#[Test]
	public function uasortSortsWithCallback(): void {
		$collection = new Collection([3, 1, 4, 1, 5]);
		$sorted = $collection->uasort(static fn (int $a, int $b): int => $a <=> $b);
		$this->assertSame([1, 1, 3, 4, 5], $sorted->values()->toArray());
	}

	#[Test]
	public function asortSortsByField(): void {
		$collection = new Collection([
			['name' => 'Charlie', 'age' => 35],
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$sorted = $collection->asort('age');
		$this->assertSame('Alice', $sorted->first()['name']);
		$this->assertSame('Charlie', $sorted->last()['name']);
	}

	#[Test]
	public function asortWithoutFieldSortsValues(): void {
		$collection = new Collection([3, 1, 4, 1, 5]);
		$sorted = $collection->asort();
		$this->assertSame([1, 1, 3, 4, 5], $sorted->values()->toArray());
	}

	#[Test]
	public function sortBySortsByField(): void {
		$collection = new Collection([
			['name' => 'Charlie', 'age' => 35],
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$sorted = $collection->sortBy('age');
		$this->assertSame('Alice', $sorted->first()['name']);
	}

	#[Test]
	public function sortByWithNumericOptions(): void {
		$collection = new Collection([
			['value' => '10'],
			['value' => '2'],
			['value' => '1'],
		]);
		$sorted = $collection->sortBy('value', \SORT_NUMERIC);
		$this->assertSame('1', $sorted->first()['value']);
		$this->assertSame('10', $sorted->last()['value']);
	}

	#[Test]
	public function sortByDescending(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$sorted = $collection->sortBy('age', \SORT_REGULAR, true);
		$this->assertSame('Bob', $sorted->first()['name']);
	}

	#[Test]
	public function sortByDescSortsDescending(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$sorted = $collection->sortByDesc('age');
		$this->assertSame('Bob', $sorted->first()['name']);
	}

	#[Test]
	public function sortByDescWithNumericOptions(): void {
		$collection = new Collection([
			['value' => '10'],
			['value' => '2'],
			['value' => '1'],
		]);
		$sorted = $collection->sortByDesc('value', \SORT_NUMERIC);
		$this->assertSame('10', $sorted->first()['value']);
		$this->assertSame('1', $sorted->last()['value']);
	}

	#[Test]
	public function sortUsingSortsWithCallback(): void {
		$collection = new Collection([3, 1, 4, 1, 5]);
		$sorted = $collection->sortUsing(static fn (int $v): int => $v);
		$this->assertSame([1, 1, 3, 4, 5], $sorted->values()->toArray());
	}

	#[Test]
	public function sortUsingWithNumericOptions(): void {
		$collection = new Collection([
			['value' => '10'],
			['value' => '2'],
			['value' => '1'],
		]);
		$sorted = $collection->sortUsing(static fn (array $item): int => (int)$item['value'], \SORT_NUMERIC);
		$this->assertSame('1', $sorted->first()['value']);
	}

	#[Test]
	public function sortUsingDescending(): void {
		$collection = new Collection([3, 1, 4, 1, 5]);
		$sorted = $collection->sortUsing(static fn (int $v): int => $v, \SORT_REGULAR, true);
		$this->assertSame([5, 4, 3, 1, 1], $sorted->values()->toArray());
	}

	#[Test]
	public function sortUsingDescSortsDescendingWithCallback(): void {
		$collection = new Collection([3, 1, 4, 1, 5]);
		$sorted = $collection->sortUsingDesc(static fn (int $v): int => $v);
		$this->assertSame([5, 4, 3, 1, 1], $sorted->values()->toArray());
	}

	#[Test]
	public function sortUsingDescWithNumericOptions(): void {
		$collection = new Collection([
			['value' => '10'],
			['value' => '2'],
			['value' => '1'],
		]);
		$sorted = $collection->sortUsingDesc(static fn (array $item): int => (int)$item['value'], \SORT_NUMERIC);
		$this->assertSame('10', $sorted->first()['value']);
	}

	#[Test]
	public function ksortSortsKeysAscending(): void {
		$collection = new Collection(['b' => 2, 'a' => 1, 'c' => 3]);
		$sorted = $collection->ksort();
		$this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $sorted->toArray());
	}

	#[Test]
	public function ksortWithNumericOptions(): void {
		$collection = new Collection(['10' => 1, '2' => 2, '1' => 3]);
		$sorted = $collection->ksort(\SORT_NUMERIC);
		$this->assertSame(['1' => 3, '2' => 2, '10' => 1], $sorted->toArray());
	}

	#[Test]
	public function krsortSortsKeysDescending(): void {
		$collection = new Collection(['b' => 2, 'a' => 1, 'c' => 3]);
		$sorted = $collection->krsort();
		$this->assertSame(['c' => 3, 'b' => 2, 'a' => 1], $sorted->toArray());
	}

	#[Test]
	public function krsortWithNumericOptions(): void {
		$collection = new Collection(['10' => 1, '2' => 2, '1' => 3]);
		$sorted = $collection->krsort(\SORT_NUMERIC);
		$this->assertSame(['10' => 1, '2' => 2, '1' => 3], $sorted->toArray());
	}

	// ─── Grouping ───────────────────────────────────────────────────────

	#[Test]
	public function groupByGroupsWithCallback(): void {
		$collection = new Collection([1, 2, 3, 4, 5, 6]);
		$grouped = $collection->groupBy(static fn (int $v): string => $v % 2 === 0 ? 'even' : 'odd');
		$this->assertCount(2, $grouped);
		$this->assertSame([1, 3, 5], $grouped->get('odd')->values()->toArray());
		$this->assertSame([2, 4, 6], $grouped->get('even')->values()->toArray());
	}

	#[Test]
	public function groupByStringField(): void {
		$collection = new Collection([
			['category' => 'A', 'value' => 1],
			['category' => 'B', 'value' => 2],
			['category' => 'A', 'value' => 3],
		]);
		$grouped = $collection->groupBy('category');
		$this->assertCount(2, $grouped);
		$this->assertSame(2, $grouped->get('A')->count());
	}

	#[Test]
	public function groupByStringGroupsByStringField(): void {
		$collection = new Collection([
			['category' => 'A', 'value' => 1],
			['category' => 'B', 'value' => 2],
			['category' => 'A', 'value' => 3],
		]);
		$grouped = $collection->groupByString('category');
		$this->assertCount(2, $grouped);
		$this->assertSame(2, $grouped->get('A')->count());
	}

	#[Test]
	public function groupByStringWithCallable(): void {
		$collection = new Collection([1, 2, 3, 4, 5, 6]);
		$grouped = $collection->groupByString(static fn (int $v): string => $v % 2 === 0 ? 'even' : 'odd');
		$this->assertCount(2, $grouped);
		$this->assertSame([1, 3, 5], $grouped->get('odd')->values()->toArray());
	}

	#[Test]
	public function groupByIntGroupsByIntField(): void {
		$collection = new Collection([
			['group' => 1, 'value' => 'a'],
			['group' => 2, 'value' => 'b'],
			['group' => 1, 'value' => 'c'],
		]);
		$grouped = $collection->groupByInt('group');
		$this->assertCount(2, $grouped);
		$this->assertSame(2, $grouped->get(1)->count());
	}

	#[Test]
	public function groupByIntWithCallable(): void {
		$collection = new Collection([1, 2, 3, 4, 5, 6]);
		$grouped = $collection->groupByInt(static fn (int $v): int => $v % 3);
		$this->assertCount(3, $grouped);
		$this->assertSame([3, 6], $grouped->get(0)->values()->toArray());
	}

	#[Test]
	public function keyByIntKeysByIntCallback(): void {
		$collection = new Collection([
			['id' => 10, 'name' => 'Alice'],
			['id' => 20, 'name' => 'Bob'],
		]);
		$keyed = $collection->keyByInt('id');
		$this->assertSame('Alice', $keyed->get(10)['name']);
		$this->assertSame('Bob', $keyed->get(20)['name']);
	}

	#[Test]
	public function keyByStringKeysByStringCallback(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$keyed = $collection->keyByString('name');
		$this->assertSame(25, $keyed->get('Alice')['age']);
	}

	#[Test]
	public function keyByKeysByField(): void {
		$collection = new Collection([
			['id' => 10, 'name' => 'Alice'],
			['id' => 20, 'name' => 'Bob'],
		]);
		$keyed = $collection->keyBy('id');
		$this->assertSame('Alice', $keyed->get(10)['name']);
		$this->assertSame('Bob', $keyed->get(20)['name']);
	}

	#[Test]
	public function keyByUsingKeysByCallback(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$keyed = $collection->keyByUsing(static fn (array $item): string => strtolower($item['name']));
		$this->assertSame(25, $keyed->get('alice')['age']);
		$this->assertSame(30, $keyed->get('bob')['age']);
	}

	// ─── Aggregation ──────────────────────────────────────────────────

	#[Test]
	public function countReturnsItemCount(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$this->assertSame(5, $collection->count());
	}

	#[Test]
	public function sumReturnsTotal(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$this->assertSame(15, $collection->sum());
	}

	#[Test]
	public function sumWithCallback(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 20],
			['value' => 30],
		]);
		$this->assertSame(60, $collection->sum('value'));
	}

	#[Test]
	public function sumWithCallable(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 20],
			['value' => 30],
		]);
		$this->assertSame(60, $collection->sum(static fn (array $item): int => $item['value']));
	}

	#[Test]
	public function sumReturnsFloatForFloatValues(): void {
		$collection = new Collection([1.5, 2.5, 3.0]);
		$this->assertEqualsWithDelta(7.0, $collection->sum(), 0.000_1);
	}

	#[Test]
	public function minReturnsMinimum(): void {
		$collection = new Collection([3, 1, 4, 1, 5]);
		$this->assertSame(1, $collection->min());
	}

	#[Test]
	public function minWithStringKey(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 5],
			['value' => 20],
		]);
		$this->assertSame(5, $collection->min('value'));
	}

	#[Test]
	public function minWithCallable(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 5],
			['value' => 20],
		]);
		$this->assertSame(5, $collection->min(static fn (array $item): int => $item['value']));
	}

	#[Test]
	public function maxReturnsMaximum(): void {
		$collection = new Collection([3, 1, 4, 1, 5]);
		$this->assertSame(5, $collection->max());
	}

	#[Test]
	public function maxWithStringKey(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 5],
			['value' => 20],
		]);
		$this->assertSame(20, $collection->max('value'));
	}

	#[Test]
	public function maxWithCallable(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 5],
			['value' => 20],
		]);
		$this->assertSame(20, $collection->max(static fn (array $item): int => $item['value']));
	}

	#[Test]
	public function avgReturnsAverage(): void {
		$collection = new Collection([10, 20, 30]);
		$this->assertEqualsWithDelta(20.0, $collection->avg(), 0.000_1);
	}

	#[Test]
	public function avgWithStringKey(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 20],
			['value' => 30],
		]);
		$this->assertEqualsWithDelta(20.0, $collection->avg('value'), 0.000_1);
	}

	#[Test]
	public function avgWithCallable(): void {
		$collection = new Collection([
			['value' => 10],
			['value' => 20],
			['value' => 30],
		]);
		$this->assertEqualsWithDelta(20.0, $collection->avg(static fn (array $item): int => $item['value']), 0.000_1);
	}

	#[Test]
	public function avgReturnsNullForEmptyCollection(): void {
		$collection = new Collection([]);
		$this->assertNull($collection->avg());
	}

	#[Test]
	public function reduceReducesToSingleValue(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$sum = $collection->reduce(static fn (int $carry, int $item): int => $carry + $item, 0);
		$this->assertSame(15, $sum);
	}

	#[Test]
	public function countByUsingCountsWithCallback(): void {
		$collection = new Collection([1, 2, 3, 4, 5, 6]);
		$counted = $collection->countByUsing(static fn (int $v): string => $v % 2 === 0 ? 'even' : 'odd');
		$this->assertSame(['odd' => 3, 'even' => 3], $counted->toArray());
	}

	// ─── Inspection ─────────────────────────────────────────────────────

	#[Test]
	public function isEmptyReturnsTrueForEmpty(): void {
		$collection = new Collection([]);
		$this->assertTrue($collection->isEmpty());
	}

	#[Test]
	public function isEmptyReturnsFalseForNonEmpty(): void {
		$collection = new Collection([1]);
		$this->assertFalse($collection->isEmpty());
	}

	#[Test]
	public function isNotEmptyReturnsOpposite(): void {
		$this->assertFalse((new Collection([]))->isNotEmpty());
		$this->assertTrue((new Collection([1]))->isNotEmpty());
	}

	#[Test]
	public function containsOneItemReturnsTrueForSingle(): void {
		$collection = new Collection([42]);
		$this->assertTrue($collection->containsOneItem());
	}

	#[Test]
	public function containsOneItemReturnsFalseForMultiple(): void {
		$collection = new Collection([1, 2]);
		$this->assertFalse($collection->containsOneItem());
	}

	#[Test]
	public function containsChecksValueExistence(): void {
		$collection = new Collection([1, 2, 3]);
		$this->assertTrue($collection->contains(2));
		$this->assertFalse($collection->contains(99));
	}

	#[Test]
	public function containsWithStringKeyAndOperator(): void {
		$collection = new Collection([
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		]);
		$this->assertTrue($collection->contains('age', 25));
		$this->assertFalse($collection->contains('age', 99));
	}

	#[Test]
	public function containsWithCallback(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$this->assertTrue($collection->contains(static fn (int $v): bool => $v > 4));
		$this->assertFalse($collection->contains(static fn (int $v): bool => $v > 10));
	}

	#[Test]
	public function containsStrictUsesStrictComparison(): void {
		$collection = new Collection([1, '1', 2]);
		$this->assertTrue($collection->containsStrict(static fn ($v) => $v === 1));
		$this->assertTrue($collection->containsStrict(static fn ($v) => $v === '1'));
		$this->assertFalse($collection->containsStrict(static fn ($v) => $v === 99));
	}

	#[Test]
	public function containsStrictWithValueOnly(): void {
		$collection = new Collection([1, 2]);
		$this->assertTrue($collection->containsStrict(1));
		$this->assertFalse($collection->containsStrict('1'));
	}

	// ─── Slicing & Merging ──────────────────────────────────────────────

	#[Test]
	public function sliceReturnsSubset(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$sliced = $collection->slice(1, 3);
		$this->assertSame([2, 3, 4], $sliced->values()->toArray());
	}

	#[Test]
	public function sliceWithoutLength(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$sliced = $collection->slice(2);
		$this->assertSame([3, 4, 5], $sliced->values()->toArray());
	}

	#[Test]
	public function mergeCombinesCollections(): void {
		$collection = new Collection([1, 2]);
		$merged = $collection->merge([3, 4]);
		$this->assertSame([1, 2, 3, 4], $merged->values()->toArray());
	}

	#[Test]
	public function mergeCombinesWithCollection(): void {
		$collection = new Collection([1, 2]);
		$merged = $collection->merge(new Collection([3, 4]));
		$this->assertSame([1, 2, 3, 4], $merged->values()->toArray());
	}

	#[Test]
	public function concatAppendsItems(): void {
		$collection = new Collection([1, 2]);
		$concat = $collection->concat([3, 4]);
		$this->assertSame([1, 2, 3, 4], $concat->values()->toArray());
	}

	#[Test]
	public function concatAppendsCollection(): void {
		$collection = new Collection([1, 2]);
		$concat = $collection->concat(new Collection([3, 4]));
		$this->assertSame([1, 2, 3, 4], $concat->values()->toArray());
	}

	#[Test]
	public function diffReturnsItemsNotInOther(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$diff = $collection->diff([2, 4, 6]);
		$this->assertSame([0 => 1, 2 => 3, 4 => 5], $diff->toArray());
	}

	#[Test]
	public function diffReturnsItemsNotInCollection(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$diff = $collection->diff(new Collection([2, 4, 6]));
		$this->assertSame([0 => 1, 2 => 3, 4 => 5], $diff->toArray());
	}

	// ─── Iteration ──────────────────────────────────────────────────────

	#[Test]
	public function getIteratorReturnsTraversable(): void {
		$collection = new Collection([1, 2, 3]);
		$items = [];
		foreach ($collection as $item) {
			$items[] = $item;
		}
		$this->assertSame([1, 2, 3], $items);
	}

	#[Test]
	public function eachExecutesCallback(): void {
		$collection = new Collection([1, 2, 3]);
		$items = [];
		$collection->each(static function (int $v, int $k) use (&$items): ?bool {
			$items[$k] = $v;
			return null;
		});
		$this->assertSame([1, 2, 3], $items);
	}

	#[Test]
	public function eachStopsOnFalse(): void {
		$collection = new Collection([1, 2, 3]);
		$items = [];
		$collection->each(static function (int $v) use (&$items): ?bool {
			$items[] = $v;
			return $v === 1 ? null : false;
		});
		$this->assertSame([1, 2], $items);
	}

	// ─── String Operations ──────────────────────────────────────────────

	#[Test]
	public function joinJoinsItems(): void {
		$collection = new Collection(['a', 'b', 'c']);
		$this->assertSame('a, b, c', $collection->join(', '));
	}

	#[Test]
	public function joinWithFinalGlue(): void {
		$collection = new Collection(['a', 'b', 'c']);
		$this->assertSame('a, b and c', $collection->join(', ', ' and '));
	}

	// ─── Conversion ─────────────────────────────────────────────────────

	#[Test]
	public function toArrayReturnsPlainArray(): void {
		$collection = new Collection(['a' => 1, 'b' => 2]);
		$this->assertSame(['a' => 1, 'b' => 2], $collection->toArray());
	}

	#[Test]
	public function toListReturnsPlainList(): void {
		$collection = new Collection(['a' => 1, 'b' => 2]);
		$this->assertSame([1, 2], $collection->toList());
	}

	#[Test]
	public function toListConvertsArrayableItemsToArrays(): void {
		$collection = new Collection([
			new Collection([1, 2]),
			new Collection([3, 4]),
		]);
		$this->assertSame([[1, 2], [3, 4]], $collection->toList());
	}

	// ─── Random ─────────────────────────────────────────────────────────

	#[Test]
	public function pickRandomReturnsSingleItem(): void {
		$collection = new Collection([42]);
		$this->assertSame(42, $collection->pickRandom());
	}

	#[Test]
	public function pickRandomReturnsItemFromCollection(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$item = $collection->pickRandom();
		$this->assertContains($item, [1, 2, 3, 4, 5]);
	}

	// ─── Dictionary Mapping ─────────────────────────────────────────────

	#[Test]
	public function mapToDictionaryGroupsItems(): void {
		$collection = new Collection([1, 2, 3, 4, 5]);
		$dict = $collection->mapToDictionary(static fn (int $v): array => [
			$v % 2 === 0 ? 'even' : 'odd' => $v,
		]);
		$this->assertTrue($dict->has('even'));
		$this->assertTrue($dict->has('odd'));
		$this->assertContains(2, $dict->get('even'));
		$this->assertContains(1, $dict->get('odd'));
	}

	#[Test]
	public function toArrayRecursivelyConvertsNestedCollections(): void {
		$nested = new Collection([
			'users' => new Collection([
				['name' => 'Alice'],
				['name' => 'Bob'],
			]),
			'meta' => new Collection(['count' => 2]),
		]);

		$array = $nested->toArray();

		$this->assertIsArray($array['users']);
		$this->assertSame([['name' => 'Alice'], ['name' => 'Bob']], $array['users']);
		$this->assertIsArray($array['meta']);
		$this->assertSame(['count' => 2], $array['meta']);
	}

	#[Test]
	public function toArrayRecursivelyConvertsNestedArraysAndIterables(): void {
		$nested = new Collection([
			'plainArray' => [
				new Collection(['a' => 1]),
				['b' => 2],
			],
			'generator' => (static function (): \Generator {
				yield 'x' => new Collection([10, 20]);
				yield 'y' => ['z' => 30];
			})(),
		]);

		$array = $nested->toArray();

		$this->assertIsArray($array['plainArray']);
		$this->assertSame(['a' => 1], $array['plainArray'][0]);
		$this->assertSame(['b' => 2], $array['plainArray'][1]);
		$this->assertIsArray($array['generator']);
		$this->assertSame([10, 20], $array['generator']['x']);
		$this->assertSame(['z' => 30], $array['generator']['y']);
	}

	#[Test]
	public function toArrayRecursivelyConvertsDeeplyNestedStructures(): void {
		$deep = new Collection([
			'level1' => new Collection([
				'level2' => new Collection([
					'level3' => new Collection([
						'level4' => ['deep' => 'value'],
					]),
				]),
			]),
			'mixed' => [
				'array' => [
					'collection' => new Collection([
						'generator' => (static function (): \Generator {
							yield 'deepest' => new Collection(['found' => true]);
						})(),
					]),
				],
			],
		]);

		$array = $deep->toArray();

		// 4 Ebenen tief verschachtelte Collections
		$this->assertIsArray($array['level1']);
		$this->assertIsArray($array['level1']['level2']);
		$this->assertIsArray($array['level1']['level2']['level3']);
		$this->assertIsArray($array['level1']['level2']['level3']['level4']);
		$this->assertSame(['deep' => 'value'], $array['level1']['level2']['level3']['level4']);

		// Gemischte Struktur: array -> collection -> generator -> collection
		$this->assertIsArray($array['mixed']['array']['collection']['generator']);
		$this->assertIsArray($array['mixed']['array']['collection']['generator']['deepest']);
		$this->assertSame(['found' => true], $array['mixed']['array']['collection']['generator']['deepest']);
	}

	#[Test]
	public function keepOnlyFiltersByClass(): void {
		$collection = new Collection([
			new \stdClass(),
			new Collection([1, 2]),
			new \stdClass(),
			'not an object',
		]);
		$filtered = $collection->keepOnly(\stdClass::class);
		$this->assertCount(2, $filtered);
		$this->assertContainsOnly(\stdClass::class, $filtered->toArray());
	}

	#[Test]
	public function jsonSerializeReturnsArray(): void {
		$collection = new Collection(['a' => 1, 'b' => 2]);
		$json = json_encode($collection);
		$this->assertSame('{"a":1,"b":2}', $json);
	}

	#[Test]
	public function jsonSerializeRecursivelyConvertsNestedCollections(): void {
		$collection = new Collection([
			'nested' => new Collection(['x' => 10]),
		]);
		$json = json_encode($collection);
		$this->assertSame('{"nested":{"x":10}}', $json);
	}
}
