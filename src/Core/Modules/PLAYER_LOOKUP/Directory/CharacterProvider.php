<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use Nadybot\Core\DBSchema\Player;

interface CharacterProvider extends DirectoryProvider {
	/**
	 * Look up a character
	 *
	 * @return ?Player The character, or null if it doesn't exist
	 *
	 * @throws DirectoryUnavailableException if the provider cannot answer right now
	 */
	public function lookupCharacter(string $name, int $dimension): ?Player;
}
