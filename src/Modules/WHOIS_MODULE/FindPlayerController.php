<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'findplayer',
		accessLevel: AccessLevel::Guest,
		description: 'Find a player by name',
	)
]
class FindPlayerController extends ModuleInstance {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	/** Find a player by name in the local database */
	#[NCA\HandlesCommand('findplayer')]
	public function findplayerCommand(CmdContext $context, string $search): void {
		$players = $this->playerManager->searchForPlayers(
			$search,
			$this->config->main->dimension
		);
		$count = $players->count();

		if ($count === 0) {
			$msg = "Could not find any players matching <highlight>{$search}<end>.";
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Results<end>\n";
		foreach ($players as $player) {
			$blob .= '<tab>' . $this->playerManager->getInfo($player, false) . "\n";
		}
		$msg = Text::makeBlob("Search results for \"{$search}\" ({$count})", $blob);

		$context->reply($msg);
	}
}
