<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

interface OrgRosterProvider extends DirectoryProvider {
	/**
	 * Look up an organization and all of its members
	 *
	 * Members whose character id is unknown have a charid of 0
	 *
	 * @param bool $forceUpdate Ignore cached data
	 *
	 * @return ?OrgRosterResult The organization, or null if it doesn't exist
	 *
	 * @throws DirectoryUnavailableException if the provider cannot answer right now
	 */
	public function lookupOrgRoster(int $orgId, int $dimension, bool $forceUpdate=false): ?OrgRosterResult;
}
