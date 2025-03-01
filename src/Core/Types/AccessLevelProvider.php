<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This interface allows instances to define which access levels apply to whom */
interface AccessLevelProvider {
	/**
	 * Returns the access level of $sender, ignoring inherited access levels.
	 * If this provider doesn't give $sender any access level, returns null
	 */
	public function getSingleAccessLevel(string $sender): ?AccessLevel;
}
