<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

/** Represents a test case together with its group and position in the collection */
class TestPosition {
	public function __construct(
		public readonly TestGroup $group,
		public readonly TestCase $test,
		public readonly int $position,
	) {
	}
}
