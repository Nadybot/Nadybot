<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandReply,
	ConfigFile,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerHistory,
	Modules\PLAYER_LOOKUP\PlayerHistoryManager,
	ParamClass\PCharacter,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "history",
		accessLevel: "all",
		description: "Show history of a player",
	)
]
class PlayerHistoryController extends ModuleInstance {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public PlayerHistoryManager $playerHistoryManager;

	/**
	 * Show the history of a player on this dimension or &lt;dimension&gt;
	 *
	 * Valid dimensions are 1 (Atlantean), 2 (Rimor), and 5 (New server)
	 */
	#[NCA\HandlesCommand("history")]
	public function playerHistoryCommand(CmdContext $context, PCharacter $char, ?int $dimension): void {
		$name = $char();
		$dimension ??= $this->config->dimension;

		$this->playerHistoryManager->asyncLookup($name, $dimension, [$this, "servePlayerHistory"], $name, $dimension, $context);
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

			if ($entry->deleted === "1") {
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

			if (!isset($entry->guild_name) || $entry->guild_name == "") {
				$guild = "Not in a guild";
			} else {
				$guild = $entry->guild_name;
				if (isset($entry->guild_rank_name) && strlen($entry->guild_rank_name)) {
					$guild .= " (<highlight>{$entry->guild_rank_name}<end>)";
				}
			}
			$level = $this->text->alignNumber((int)$entry->level, 3);

			$blob .= "$date <highlight>|<end>  $level  <highlight>|<end> $ailevel <highlight>|<end> $faction <highlight>|<end> $entry->breed <highlight>|<end> $guild\n";
		}
		$blob .= "\nHistory provided by Auno.org, Chrisax, and Athen Paladins";
		$msg = $this->text->makeBlob("History of $name for RK{$dimension}", $blob, null, $header);

		$sendto->reply($msg);
	}
}
