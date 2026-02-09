<?php declare(strict_types=1);

namespace Nadybot\Modules\TRACKER_MODULE;

class OnlineGroup {
	/**
	 * Represents a group of online tracked users
	 *
	 * @param string                  $title   The title of the group
	 * @param int|string              $sort    The sort order of the group
	 * @param list<OnlineTrackedUser> $members The members of the group
	 */
	public function __construct(
		public readonly string $title,
		public readonly int|string $sort,
		public array $members=[],
	) {
	}
}
