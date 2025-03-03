<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

class TestCollection {
	/**
	 * @param string          $name   Name of the test collection
	 * @param list<TestGroup> $groups One or more test groups that make up
	 *                                this test collection
	 */
	public function __construct(
		public readonly string $name,
		#[CastListToType(TestGroup::class)] public readonly array $groups,
	) {
	}
}
