<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use Nadybot\Core\{
	BuddylistManager,
	CommandAlias,
	CommandReply,
	Event,
	DB,
	DBSchema\Player,
	Modules\ALTS\AltsController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'whois',
 *		accessLevel = 'member',
 *		description = 'Show character info, online status, and name history',
 *		help        = 'whois.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'lookup',
 *		accessLevel = 'all',
 *		description = 'Find the charId for a character',
 *		help        = 'lookup.txt'
 *	)
 */
class WhoisController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public AltsController $altsController;
	
	/** @Inject */
	public PlayerManager $playerManager;
	
	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public CommandAlias $commandAlias;
	
	/** @var CharData[] */
	private array $nameHistoryCache = [];
	
	private $replyInfo = [];
	
	/** @Setup */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "name_history");

		$this->commandAlias->register($this->moduleName, "whois", "w");
		$this->commandAlias->register($this->moduleName, "whois", "is");
	}
	
	/**
	 * @Event("timer(1min)")
	 * @Description("Save cache of names and charIds to database")
	 */
	public function saveCharIds(Event $eventObj): void {
		if (empty($this->nameHistoryCache) || $this->db->inTransaction()) {
			return;
		}
		$this->db->beginTransaction();
		foreach ($this->nameHistoryCache as $entry) {
			if ($this->db->getType() == DB::SQLITE) {
				$this->db->exec(
					"INSERT OR IGNORE INTO name_history (name, charid, dimension, dt) ".
					"VALUES (?, ?, <dim>, ?)",
					$entry->name,
					$entry->charid,
					time()
				);
			} else {
				$this->db->exec(
					"INSERT IGNORE INTO name_history (name, charid, dimension, dt) ".
					"VALUES (?, ?, <dim>, ?)",
					$entry->name,
					$entry->charid,
					time()
				);
			}
		}
		$this->db->commit();

		$this->nameHistoryCache = [];
	}
	
	/**
	 * @Event("packet(20)")
	 * @Event("packet(21)")
	 * @Description("Records names and charIds")
	 */
	public function recordCharIds(Event $eventObj): void {
		$packet = $eventObj->packet;
		if (!$this->util->isValidSender($packet->args[0])) {
			return;
		}
		$charData = new CharData();
		$charData->charid = $packet->args[0];
		$charData->name = $packet->args[1];
		if ($charData->charid === -1 || $charData->charid === 4294967295) {
			return;
		}
		$this->nameHistoryCache []= $charData;
	}
	
	/**
	 * @HandlesCommand("lookup")
	 * @Matches("/^lookup (\d+)$/i")
	 */
	public function lookupIdCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$charID = (int)$args[1];
		/** @var NameHistory[] */
		$players = $this->db->fetchAll(
			NameHistory::class,
			"SELECT * FROM name_history ".
			"WHERE charid = ? AND dimension = <dim> ".
			"ORDER BY dt DESC",
			$charID
		);
		$count = count($players);

		$blob = "<header2>Known names for {$charID}<end>\n";
		if ($count === 0) {
			$msg = "No history available for character id <highlight>$charID<end>.";
			$sendto->reply($msg);
			return;
		}
		foreach ($players as $player) {
			$link = $this->text->makeChatcmd($player->name, "/tell <myname> lookup $player->name");
			$blob .= "<tab>$link " . $this->util->date($player->dt) . "\n";
		}
		$msg = $this->text->makeBlob("Name History for $charID ($count)", $blob);

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("lookup")
	 * @Matches("/^lookup (.+)$/i")
	 */
	public function lookupNameCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));

		$players = $this->db->query("SELECT * FROM name_history WHERE name LIKE ? AND dimension = <dim> ORDER BY dt DESC", $name);
		/** @var NameHistory[] */
		$players = $this->db->fetchAll(
			NameHistory::class,
			"SELECT * FROM name_history ".
			"WHERE name = ? AND dimension = <dim> ".
			"ORDER BY dt DESC",
			$name
		);
		$count = count($players);

		$blob = "<header2>Known IDs of {$name}<end>\n";
		if ($count === 0) {
			$msg = "No history available for character <highlight>$name<end>.";
			$sendto->reply($msg);
			return;
		}
		foreach ($players as $player) {
			$link = $this->text->makeChatcmd((string)$player->charid, "/tell <myname> lookup $player->charid");
			$blob .= "<tab>$link " . $this->util->date($player->dt) . "\n";
		}
		$msg = $this->text->makeBlob("Character Ids for $name ($count)", $blob);

		$sendto->reply($msg);
	}
	
	public function getNameHistory(int $charID, int $dimension): string {
		$sql = "SELECT * FROM name_history ".
			"WHERE charid = ? ".
			"AND dimension = ? ".
			"ORDER BY dt DESC";
		$data = $this->db->fetchAll(NameHistory::class, $sql, $charID, $dimension);

		$blob = "<header2>Name History<end>\n";
		if (count($data) > 0) {
			foreach ($data as $row) {
				$blob .= "<tab><highlight>{$row->name}<end> " . $this->util->date($row->dt) . "\n";
			}
		} else {
			$blob .= "<tab>No name history available\n";
		}

		return $blob;
	}
	
	/**
	 * @HandlesCommand("whois")
	 * @Matches("/^whois (.+)$/i")
	 */
	public function whoisNameCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$uid = $this->chatBot->get_uid($name);
		$dimension = (int)$this->chatBot->vars['dimension'];
		if ($uid) {
			$online = $this->buddylistManager->isOnline($name);
			if ($online === null) {
				$this->replyInfo[$name] = $sendto;
				$this->buddylistManager->add($name, 'is_online');
			} else {
				$this->getOutputAsync([$sendto, "reply"], $name, $online);
			}
		} elseif (strlen($name) < 4) {
			$sendto->reply("<highlight>{$name}<end> is too short. Minimum length is 4 characters.");
		} elseif (strlen($name) > 12) {
			$sendto->reply("<highlight>{$name}<end> is too long. Maximum length is 12 characters.");
		} else {
			$this->playerManager->lookupAsync($name, $dimension, [$this, "showInactivePlayer"], $sendto, $name);
		}
	}

	/**
	 * Callback to render whois of players without uid
	 */
	public function showInactivePlayer(?Player $player, CommandReply $sendto, string $name): void {
		if ($player === null) {
			$sendto->reply("Character <highlight>{$name}<end> does not exist.");
			return;
		}
		$this->getOutputAsync([$sendto, "reply"], $name, false);
	}

	private function playerToWhois(callable $callback, ?Player $whois, string $name, bool $online): void {
		$charID = $this->chatBot->get_uid($name);
		$lookupNameLink = $this->text->makeChatcmd("Lookup", "/tell <myname> lookup $name");
		if ($charID) {
			$lookupCharIdLink = $this->text->makeChatcmd("Lookup", "/tell <myname> lookup $charID");
		}

		if ($whois === null) {
			$blob = "<orange>Note: Could not retrieve detailed info for character.<end>\n\n";
			$blob .= "Name: <highlight>{$name}<end> {$lookupNameLink}\n";
			if ($charID) {
				$blob .= "Character ID: <highlight>{$charID}<end> {$lookupCharIdLink}\n\n";
			}
			$blob .= $this->getNameHistory($charID, $this->chatBot->vars['dimension']);

			$msg = $this->text->makeBlob("Basic Info for $name", $blob);
			$callback($msg);
			return;
		}

		$blob = "Name: <highlight>" . $this->getFullName($whois) . "<end> {$lookupNameLink}\n";
		if (isset($whois->guild) && $whois->guild !== "") {
			$orglistLink = $this->text->makeChatcmd("See members", "/tell <myname> orglist $whois->guild_id");
			$orginfoLink = $this->text->makeChatcmd("Info", "/tell <myname> whoisorg $whois->guild_id");
			$blob .= "Org: <highlight>{$whois->guild}<end> (<highlight>{$whois->guild_id}<end>) [$orginfoLink] [$orglistLink]\n";
			$blob .= "Org Rank: <highlight>{$whois->guild_rank}<end> (<highlight>{$whois->guild_rank_id}<end>)\n";
		}
		$blob .= "Breed: <highlight>{$whois->breed}<end>\n";
		$blob .= "Gender: <highlight>{$whois->gender}<end>\n";
		$blob .= "Profession: <highlight>{$whois->profession}<end> (<highlight>" . trim($whois->prof_title) . "<end>)\n";
		$blob .= "Level: <highlight>{$whois->level}<end>\n";
		$blob .= "AI Level: <green>{$whois->ai_level}<end> (<highlight>{$whois->ai_rank}<end>)\n";
		$blob .= "Faction: <".strtolower($whois->faction).">{$whois->faction}<end>\n";
		$blob .= "Head Id: <highlight>{$whois->head_id}<end>\n";
		$blob .= "PVP Rating: <highlight>{$whois->pvp_rating}<end>\n";
		$blob .= "PVP Title: <highlight>{$whois->pvp_title}<end>\n";
		$blob .= "Status: ";
		if ($online) {
			$blob .= "<green>Online<end>\n";
		} elseif ($charID === false) {
			$blob .= "<red>Inactive<end>\n";
		} else {
			$blob .= "<red>Offline<end>\n";
		}
		if ($charID !== false) {
			$blob .= "Character ID: <highlight>{$whois->charid}<end> {$lookupCharIdLink}\n\n";
		}

		$blob .= "Source: <highlight>{$whois->source}<end>\n\n";

		if ($charID !== false) {
			$blob .= $this->getNameHistory($charID, $this->chatBot->vars['dimension']);
		}

		$msg = $this->playerManager->getInfo($whois);
		if ($online) {
			$msg .= " :: <green>Online<end>";
		} else {
			$msg .= " :: <red>Offline<end>";
		}
		$msg .= " :: " . $this->text->makeBlob("More Info", $blob, "Detailed Info for {$name}");

		$altInfo = $this->altsController->getAltInfo($name);
		if (count($altInfo->alts) === 0) {
			$callback($msg);
			return;
		}
		$altInfo->getAltsBlobAsync(
			function($blob) use ($msg, $callback): void {
				$callback("{$msg} :: {$blob}");
			},
			true
		);
	}

	public function getOutputAsync(callable $callback, string $name, bool $online): void {
		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($callback, $name, $online): void {
				$this->playerToWhois($callback, $player, $name, $online);
			},
			$name
		);
	}

	public function getFullName(Player $whois): string {
		$msg = "";
		
		if (isset($whois->firstname)) {
			$msg .= $whois->firstname . " ";
		}

		$msg .= "\"{$whois->name}\"";

		if (isset($whois->lastname)) {
			$msg .= " " . $whois->lastname;
		}

		return $msg;
	}
	
	/**
	 * @Event("logOn")
	 * @Description("Gets online status of character")
	 */
	public function logonEvent(Event $eventObj): void {
		$name = $eventObj->sender;
		if (!isset($this->replyInfo[$name])) {
			return;
		}
		$this->getOutputAsync(
			function(string $msg) use ($name): void {
				$this->replyInfo[$name]->reply($msg);
				$this->buddylistManager->remove($name, 'is_online');
				unset($this->replyInfo[$name]);
			},
			$name,
			true
		);
	}
	
	/**
	 * @Event("logOff")
	 * @Description("Gets offline status of character")
	 */
	public function logoffEvent(Event $eventObj): void {
		$name = $eventObj->sender;
		if (!isset($this->replyInfo[$name])) {
			return;
		}
		$this->getOutputAsync(
			function(string $msg) use ($name): void {
				$this->replyInfo[$name]->reply($msg);
				$this->buddylistManager->remove($name, 'is_online');
				unset($this->replyInfo[$name]);
			},
			$name,
			false
		);
	}
}
