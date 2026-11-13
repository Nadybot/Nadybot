<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

interface OrgListProvider extends DirectoryProvider {
	/**
	 * The chunks in which the org list is downloaded, e.g. first letters
	 *
	 * @return list<string>
	 */
	public function getOrgListChunks(): array;

	/**
	 * Download one chunk of the list of all organizations
	 *
	 * @throws DirectoryUnavailableException if the provider cannot answer right now
	 */
	public function lookupOrgList(string $chunk, int $dimension): OrgListResult;
}
