<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;

class OrgRosterResult {
	/** @param bool $fromCache True if the data came from a cache and is therefore already known */
	public function __construct(
		public readonly Guild $guild,
		public readonly bool $fromCache,
	) {
	}
}
