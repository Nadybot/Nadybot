<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

/** This represents a group of tests that are grouped together */
class TestGroup {
	/**
	 * @param string         $name  Name of this test group
	 * @param list<TestCase> $tests One or more test cases that make
	 *                              up this test group
	 */
	public function __construct(
		public readonly string $name,
		#[CastListToType(TestCase::class)] public readonly array $tests,
	) {
	}
}
