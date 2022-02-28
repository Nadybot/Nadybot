<?php declare(strict_types=1);

namespace Nadybot\Core;

interface AccessLevelProvider {
	/**
	 * Returns the access level of $sender, ignoring inherited access levels.
	 * If this provider doesn't give $sender any access level, returns null
	 */
	public function getSingleAccessLevel(string $sender): ?string;
}
