<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use Nadybot\Core\Types\{Faction, Government};

/** One organization in the list of all organizations */
class OrgListEntry {
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly int $numMembers,
		public readonly Faction $faction,
		public readonly Government $governingForm,
	) {
	}
}
