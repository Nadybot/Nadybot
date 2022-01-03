<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ConfigFile,
	Instance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Text,
};

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "findplayer",
		accessLevel: "all",
		description: "Find a player by name",
		help: "findplayer.txt"
	)
]
class FindPlayerController extends Instance {

		#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\HandlesCommand("findplayer")]
	public function findplayerCommand(CmdContext $context, string $search): void {
		$players = $this->playerManager->searchForPlayers(
			$search,
			$this->config->dimension
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
