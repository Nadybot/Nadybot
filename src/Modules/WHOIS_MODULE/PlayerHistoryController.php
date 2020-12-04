<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerHistory;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerHistoryManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Text;

/**
 * @author Tyrence (RK2)
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
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public PlayerHistoryManager $playerHistoryManager;

	/**
	 * @HandlesCommand("history")
	 * @Matches("/^history ([^ ]+) (\d)$/i")
	 * @Matches("/^history ([^ ]+)$/i")
	 */
	public function playerHistoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$dimension = (int)$this->chatBot->vars['dimension'];
		if (count($args) === 3) {
			$dimension = (int)$args[2];
		}

		$this->playerHistoryManager->asyncLookup($name, $dimension, [$this, "servePlayerHistory"], $name, $dimension, $sendto);
	}

	public function servePlayerHistory(?PlayerHistory $history, string $name, int $dimension, CommandReply $sendto): void {
		if ($history === null) {
			$msg = "Could not get History of $name on RK$dimension.";
			$sendto->reply($msg);
			return;
		}
		$blob = "";
		$header = "Date            Level    AI    Faction    Breed     Guild (rank)\n".
			"<highlight>_________________________________________________________________<end>\n";
		foreach ($history->data as $entry) {
			$date = $entry->last_changed->format("Y-m-d");

			if ($entry->deleted == 1) {
				$blob .= "$date <highlight>|<end>   <red>DELETED<end>\n";
				continue;
			}
			if ($entry->defender_rank == "") {
				$ailevel = 0;
			} else {
				$ailevel = (int)$entry->defender_rank;
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
			$level = $this->text->alignNumber((int)$entry->level, 3);

			$blob .= "$date <highlight>|<end>  $level  <highlight>|<end> $ailevel <highlight>|<end> $faction <highlight>|<end> $entry->breed <highlight>|<end> $guild\n";
		}
		$blob .= "\nHistory provided by Auno.org, Chrisax, and Athen Paladins";
		$msg = $this->text->makeBlob("History of $name for RK{$dimension}", $blob, null, $header);

		$sendto->reply($msg);
	}
}
