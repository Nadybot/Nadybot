<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class TestCollection {
	/** @param list<TestGroup> $groups One or more test groups that make up this test collection */
	public function __construct(
		public readonly string $name,
		#[CastListToType(TestGroup::class)] public readonly array $groups,
		public readonly ?string $condition=null,
	) {
	}

	/**
	 * Iterate over all tests in the collection, yielding their group,
	 * the test itself and the global position within the collection.
	 *
	 * @return \Generator<int, TestPosition>
	 */
	public function allTests(): \Generator {
		$position = 0;
		foreach ($this->groups as $group) {
			foreach ($group->tests as $test) {
				$currentPosition = $position++;
				yield $currentPosition => new TestPosition($group, $test, $currentPosition);
			}
		}
	}
}
