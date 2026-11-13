<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

class OrgListResult {
	/**
	 * @param list<OrgListEntry> $orgs
	 * @param bool               $fromCache True if the data came from a cache and is therefore already known
	 */
	public function __construct(
		public readonly array $orgs,
		public readonly bool $fromCache,
	) {
	}
}
