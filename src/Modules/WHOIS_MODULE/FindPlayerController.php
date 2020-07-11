<?php

namespace Budabot\Modules\WHOIS_MODULE;

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
	public $moduleName;
	
	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerManager $playerManager
	 * @Inject
	 */
	public $playerManager;
	
	/**
	 * @HandlesCommand("findplayer")
	 * @Matches("/^findplayer (.+)$/i")
	 */
	public function findplayerCommand($message, $channel, $sender, $sendto, $args) {
		$search = $args[1];
		
		$data = $this->playerManager->searchForPlayers($search, $this->chatBot->vars['dimension']);
		$count = count($data);

		if ($count > 0) {
			$blob = '';
			foreach ($data as $row) {
				$blob .= $this->playerManager->getInfo($row, false) . "\n\n";
			}
			$msg = $this->text->makeBlob("Search results for '$search' ($count)", $blob);
		} else {
			$msg = "Could not find any players matching <highlight>$search<end>.";
		}

		$sendto->reply($msg);
	}
}
