<?php

namespace Budabot\User\Modules;

/**
 * Authors:
 *  - Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'history',
 *		accessLevel = 'all',
 *		description = 'Show history of a player',
 *		help        = 'history.txt'
 *	)
 */
class PlayerHistoryController {

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
	 * @var \Budabot\Core\PlayerHistoryManager $playerHistoryManager
	 * @Inject
	 */
	public $playerHistoryManager;

	/**
	 * @HandlesCommand("history")
	 * @Matches("/^history ([^ ]+) (\d)$/i")
	 * @Matches("/^history ([^ ]+)$/i")
	 */
	public function playerHistoryCommand($message, $channel, $sender, $sendto, $args) {
		$name = ucfirst(strtolower($args[1]));
		$rk_num = $this->chatBot->vars['dimension'];
		if (count($args) == 3) {
			$rk_num = $args[2];
		}

		$history = $this->playerHistoryManager->lookup($name, $rk_num);
		if ($history === null) {
			$msg = "Could not get History of $name on RK$rk_num.";
		} else {
			$blob = "Date            Level    AI    Faction    Breed     Guild (rank)\n";
			$blob .= "<highlight>_________________________________________________________________<end>\n";
			forEach ($history->data as $entry) {
				$date = date("Y-m-d", $entry->last_changed);

				if ($entry->deleted == 1) {
					$blob .= "$date <highlight>|<end>   <red>DELETED<end>\n";
				} else {
					if ($entry->defender_rank == "") {
						$ailevel = 0;
					} else {
						$ailevel = $entry->defender_rank;
					}
					$ailevel = $this->text->alignNumber($ailevel, 2, 'green');

					if ($entry->faction == "Omni") {
						$faction = "<omni>Omni<end>    ";
					} elseif ($entry->faction == "Clan") {
						$faction = "<clan>Clan<end>     ";
					} else {
						$faction = "<neutral>Neutral<end>  ";
					}

					if ($entry->guild_name == "") {
						$guild = "Not in a guild";
					} else {
						$guild = $entry->guild_name . " (<highlight>" . $entry->guild_rank_name . "<end>)";
					}
					$level = $this->text->alignNumber($entry->level, 3);

					$blob .= "$date <highlight>|<end>  $level  <highlight>|<end> $ailevel <highlight>|<end> $faction <highlight>|<end> $entry->breed <highlight>|<end> $guild\n";
				}
			}
			$blob .= "\nHistory provided by Auno.org, Chrisax, and Athen Paladins";
			$msg = $this->text->makeBlob("History of $name for RK{$rk_num}", $blob);
		}

		$sendto->reply($msg);
	}
}
