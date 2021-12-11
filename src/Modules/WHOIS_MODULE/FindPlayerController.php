<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\CmdContext;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Text;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'findplayer',
 *		accessLevel = 'all',
 *		description = 'Find a player by name',
 *		help        = 'findplayer.txt'
 *	)
 */
class FindPlayerController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public PlayerManager $playerManager;

	/**
	 * @HandlesCommand("findplayer")
	 */
	public function findplayerCommand(CmdContext $context, string $search): void {
		$players = $this->playerManager->searchForPlayers(
			$search,
			(int)$this->chatBot->vars['dimension']
		);
		$count = count($players);

		if ($count === 0) {
			$msg = "Could not find any players matching <highlight>$search<end>.";
			$context->reply($msg);
			return;
		}
		$blob = "<header2>Results<end>\n";
		foreach ($players as $player) {
			$blob .= "<tab>" . $this->playerManager->getInfo($player, false) . "\n";
		}
		$msg = $this->text->makeBlob("Search results for \"$search\" ($count)", $blob);

		$context->reply($msg);
	}
}
