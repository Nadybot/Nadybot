<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerHistory;

interface HistoryProvider extends DirectoryProvider {
	/**
	 * Look up the history of a character
	 *
	 * @return ?PlayerHistory The history, or null if the character is unknown
	 *
	 * @throws DirectoryUnavailableException if the provider cannot answer right now
	 */
	public function lookupHistory(string $name, int $dimension): ?PlayerHistory;
}
