<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use AO\Utils;
use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
};
use Nadybot\Core\Attributes\ExposeToAI;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\{DirectoryRegistry, DirectoryUnavailableException};

#[NCA\Instance]
class PlayerHistoryManager extends ModuleInstance {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private DirectoryRegistry $directoryRegistry;

	/**
	 * Look up the history of a character's development over time (org membership, level). If the
	 * character is unknown, returns null
	 *
	 * @param string $name      The name of the character to look up
	 * @param ?int   $dimension The dimension (server) for which to get the character's history.
	 *                          Defaults to the one this bot runs on
	 *
	 * @return ?PlayerHistory Detailed character history, or null if the character is unknown
	 */
	#[ExposeToAI(name: 'get_char_history')]
	public function lookup(string $name, ?int $dimension=null): ?PlayerHistory {
		$dimension ??= $this->config->main->dimension;
		$name = Utils::normalizeCharacter($name);
		foreach ($this->directoryRegistry->historyProviders($dimension) as $provider) {
			try {
				return $provider->lookupHistory($name, $dimension);
			} catch (DirectoryUnavailableException) {
				continue;
			}
		}
		return null;
	}
}
