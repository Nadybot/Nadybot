<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\CommandReply;
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
	 * @Matches("/^findplayer (.+)$/i")
	 */
	public function findplayerCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = $args[1];
		
		$players = $this->playerManager->searchForPlayers(
			$search,
			(int)$this->chatBot->vars['dimension']
		);
		$count = count($players);

		if ($count === 0) {
			$msg = "Could not find any players matching <highlight>$search<end>.";
			$sendto->reply($msg);
			return;
		}
		$blob = "<header2>Results<end>\n";
		foreach ($players as $player) {
			$blob .= "<tab>" . $this->playerManager->getInfo($player, false) . "\n";
		}
		$msg = $this->text->makeBlob("Search results for \"$search\" ($count)", $blob);

		$sendto->reply($msg);
	}
}
