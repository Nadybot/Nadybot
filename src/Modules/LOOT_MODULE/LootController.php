<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	CommandManager,
	DB,
	Nadybot,
	SettingManager,
	Text,
	Modules\PLAYER_LOOKUP\PlayerManager,
};
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatLeaderController;
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

/**
 * @author Derroylo (RK2)
 * @author Marinerecon (RK2)
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'loot',
 *		accessLevel = 'all',
 *		description = 'Show the loot list',
 *		help        = 'flatroll.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'loot .+',
 *		accessLevel = 'rl',
 *		description = 'Modify the loot list',
 *		help        = 'flatroll.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'mloot',
 *		accessLevel = 'rl',
 *		description = 'Put multiple items on the loot list',
 *		help        = 'flatroll.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'reroll',
 *		accessLevel = 'rl',
 *		description = 'Reroll the residual loot list',
 *		help        = 'flatroll.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'flatroll',
 *		accessLevel = 'rl',
 *		description = 'Roll the loot list',
 *		help        = 'flatroll.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'add',
 *		accessLevel = 'all',
 *		description = 'Add a player to a roll slot',
 *		help        = 'add_rem.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'rem',
 *		accessLevel = 'all',
 *		description = 'Remove a player from a roll slot',
 *		help        = 'add_rem.txt'
 *	)
 */
class LootController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/**
	 * The currently rolled items
	 *
	 * @var LootItem[]
	 */
	private $loot = [];

	/**
	 * The leftovers from the last loot roll
	 *
	 * @var LootItem[]
	 */
	private $residual = [];

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"add_on_loot",
			"Confirmation messages for adding to loot",
			"edit",
			"options",
			"2",
			"tells;privatechat;privatechat and tells",
			'1;2;3',
			"mod"
		);
		$this->settingManager->add(
			$this->moduleName,
			'show_loot_pics',
			'Show pictures in loot-command',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0'
		);

		$this->commandAlias->register($this->moduleName, "flatroll", "rollloot");
		$this->commandAlias->register($this->moduleName, "flatroll", "result");
		$this->commandAlias->register($this->moduleName, "flatroll", "win");
		$this->commandAlias->register($this->moduleName, "loot addmulti", "multiloot");

		$this->commandAlias->register($this->moduleName, "loot", "list");
	}

	/**
	 * @Event("timer(30sec)")
	 * @Description("Periodically announce running loot rolls")
	 */
	public function announceLootList(): void {
		if (empty($this->loot)) {
			return;
		}
		$lootList = $this->getCurrentLootList();
		$msg = "\n".
			"<yellow>" . str_repeat("-", 76) . "<end>\n".
			"<tab>There's loot being rolled: $lootList\n".
			"<tab>Make sure you've added yourself to a slot if you want something.\n".
			"<yellow>" . str_repeat("-", 76) . "<end>";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Show a list of currently rolled loot
	 *
	 * @HandlesCommand("loot")
	 * @Matches("/^loot$/i")
	 */
	public function lootCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getCurrentLootList();
		$sendto->reply($msg);
	}

	/**
	 * Clear the current loot list
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot clear$/i")
	 */
	public function lootClearCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->loot = [];
		$this->residual = [];
		$msg = "Loot has been cleared by <highlight>$sender<end>.";
		$this->chatBot->sendPrivate($msg);

		if ($channel === 'msg') {
			$sendto->reply($msg);
		}
	}

	/**
	 * Add an item from the raid_loot to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot add (\d+)$/i")
	 */
	public function lootAddByIdCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$id = (int)$args[1];

		$sql = "SELECT *, COALESCE(a.name, r.name) AS name ".
			"FROM raid_loot r ".
			"LEFT JOIN aodb a ON (r.name = a.name AND r.ql >= a.lowql AND r.ql <= a.highql) ".
			"WHERE r.aoid IS NULL AND id = ? ".
			"UNION ".
			"SELECT *, COALESCE(a.name, r.name) AS name ".
			"FROM raid_loot r ".
			"JOIN aodb a ON (r.aoid = a.highid) ".
			"WHERE r.aoid IS NOT NULL AND id = ?";
		$row = $this->db->queryRow($sql, $id, $id);

		if ($row === null) {
			$msg = "Could not find item with id <highlight>$id<end> to add.";
			$sendto->reply($msg);
			return;
		}

		$key = $this->getLootItem($row->name);
		if ($key !== null) {
			$item = $this->loot[$key];
			$item->multiloot += $row->multiloot;
		} else {
			if (!empty($this->loot)) {
				$key = count($this->loot) + 1;
			} else {
				$key = 1;
			}

			$item = new LootItem();

			$item->name = $row->name;
			$item->icon = $row->icon;
			$item->added_by = $sender;
			$item->display = $row->name;
			if ($row->lowid) {
				$item->display = $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->name);
			}
			if (strlen($row->comment)) {
				$item->comment = " ({$row->comment})";
			}
			$item->multiloot = $row->multiloot;

			$this->loot[$key] = $item;
		}

		$msg = "$sender added <highlight>{$item->name}<end> (x$item->multiloot). To add use <symbol>add $key.";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Add an item from the raid_loot to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot auction (\d+)$/i")
	 */
	public function lootAuctionByIdCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$sql = "SELECT *, COALESCE(a.name, r.name) AS name ".
			"FROM raid_loot r ".
			"LEFT JOIN aodb a ON (r.name = a.name AND r.ql >= a.lowql AND r.ql <= a.highql) ".
			"WHERE r.aoid IS NULL AND id = ? ".
			"UNION ".
			"SELECT *, COALESCE(a.name, r.name) AS name ".
			"FROM raid_loot r ".
			"JOIN aodb a ON (r.aoid = a.highid) ".
			"WHERE r.aoid IS NOT NULL AND id = ?";
		$row = $this->db->queryRow($sql, $id, $id);

		if ($row === null) {
			$msg = "Could not find item with id <highlight>$id<end> to add.";
			$sendto->reply($msg);
			return;
		}

		$item = $row->name;
		if ($row->lowid) {
			$item = $this->text->makeItem((int)$row->lowid, $row->highid, $row->ql, $row->name);
		}
		// We want this command to always use the same rights as the bid start
		$this->commandManager->process($channel, "bid start {$item}", $sender, $sendto);
	}

	/**
	 * Add an item to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot add (.+)$/i")
	 */
	public function lootAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$input = $args[1];
		$this->addLootItem($input, 1, $sender);
	}

	/**
	 * Add multiple items to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot addmulti (\d+)x? (.+)$/i")
	 * @Matches("/^loot multiadd (\d+)x? (.+)$/i")
	 */
	public function multilootCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$multiloot = (int)$args[1];
		$input = $args[2];
		$this->addLootItem($input, $multiloot, $sender);
	}

	/**
	 * Add one item to the loot roll
	 */
	public function addLootItem(string $input, int $multiloot, string $sender, $surpressMessage=false): void {
		//Check if the item is a link
		if (preg_match("|^<a href=['\"]itemref://(\\d+)/(\\d+)/(\\d+)[\"']>(.+)</a>(.*)$|i", $input, $arr)) {
			$itemQL = (int)$arr[3];
			$itemHighID = (int)$arr[1];
			$itemLowID = (int)$arr[2];
			$itemName = $arr[4];
		} elseif (preg_match("|^(.+)<a href=[\"']itemref://(\\d+)/(\\d+)/(\\d+)[\"']>(.+)</a>(.*)$|i", $input, $arr)) {
			$itemQL = (int)$arr[4];
			$itemHighID = (int)$arr[2];
			$itemLowID = (int)$arr[3];
			$itemName = $arr[5];
		} else {
			$itemName = $input;
		}

		/** @var ?AODBEntry */
		$row = $this->db->fetch(
			AODBEntry::class,
			"SELECT * FROM aodb WHERE `name` LIKE ?",
			$itemName
		);
		if ($row !== null) {
			$itemName = $row->name;

			//Save the icon
			$looticon = $row->icon;

			//Save the aoid and ql if not set yet
			if (!isset($itemHighID)) {
				$itemLowID = $row->lowid;
				$itemHighID = $row->highid;
				$itemQL = $row->highql;
			}
		}

		// check if the item is already on the list
		$key = $this->getLootItem($itemName);
		if ($key !== null) {
			$item = $this->loot[$key];
			$item->multiloot += $multiloot;
		} else {
			// get a slot for the item
			if (!empty($this->loot)) {
				$key = count($this->loot) + 1;
			} else {
				$key = 1;
			}

			$item = new LootItem();

			$item->name = $itemName;
			$item->icon = $looticon;
			$item->added_by = $sender;
			$item->multiloot = $multiloot;

			if (isset($itemHighID)) {
				$item->display = $this->text->makeItem($itemLowID, $itemHighID, $itemQL, $itemName);
			} else {
				$item->display = $itemName;
			}
			$this->loot[$key] = $item;
		}

		$msg = "$sender added <highlight>{$item->display}<end> (x$item->multiloot) to Slot <highlight>#$key<end>.";
		$msg .= " To add use <symbol>add $key, or <symbol>rem to remove yourself.";
		if ($surpressMessage) {
			return;
		}
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Remove a single item from the loot list
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot rem (\d+)$/i")
	 */
	public function lootRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$key = (int)$args[1];
		// validate item existance on loot list
		if ($key === 0 || $key > count($this->loot)) {
			$sendto->reply("There is no item at slot <highlight>#".$key."<end>");
			return;
		}
		// if removing this item empties the list, clear the loot list properly
		if (count($this->loot) <= 1) {
			$this->loot = [];
			$this->chatBot->sendPrivate("Item in slot <highlight>#".$key."<end> was the last item in the list. The list has been cleared.");
			return;
		}
		// remove the item by shifting lower items up one slot and remove last slot
		$loop = $key;
		while ($loop < count($this->loot)) {
			$this->loot[$loop] = $this->loot[$loop+1];
			$loop++;
		}
		unset($this->loot[count($this->loot)]);
		$this->chatBot->sendPrivate("Removing item in slot <highlight>#".$key."<end>");
	}

	/**
	 * Create a new loot roll with the leftovers from the last roll
	 *
	 * @HandlesCommand("reroll")
	 * @Matches("/^reroll$/i")
	 */
	public function rerollCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		//Check if a residual list exits
		if (empty($this->residual)) {
			$msg = "There are no remaining items to re-add.";
			$sendto->reply($msg);
			return;
		}

		// Readd remaining loot
		foreach ($this->residual as $key => $item) {
			$this->loot[$key] = $item;
			$this->loot[$key]->added_by = $sender;
		}

		//Reset residual list
		$this->residual = [];
		//Show winner list
		$msg = "All remaining items have been re-added by <highlight>$sender<end>. Check <symbol>loot.";
		$this->chatBot->sendPrivate($msg);
		if ($channel !== 'priv') {
			$sendto->reply($msg);
		}

		$msg = $this->getCurrentLootList();
		$sendto->reply($msg);
	}

	/**
	 * Determine the winner(s) of the current loot roll
	 *
	 * @HandlesCommand("flatroll")
	 * @Matches("/^flatroll$/i")
	 */
	public function flatrollCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		//Check if a loot list exits
		if (empty($this->loot)) {
			$msg = "There is nothing to roll atm.";
			$sendto->reply($msg);
			return;
		}

		srand(); // get a good seed

		$list = '';
		//Roll the loot
		$resnum = 1;
		foreach ($this->loot as $key => $item) {
			$list .= "Item: <header2>{$item->name}<end>\n";
			$numUsers = count($item->users);
			if ($numUsers == 1) {
				$list .= "Winner: ";
			} else {
				$list .= "Winners: ";
			}
			if ($numUsers == 0) {
				$list .= "<highlight>No one added.<end>\n\n";
				$this->residual[$resnum] = $item;
				$resnum++;
			} else {
				if ($item->multiloot > 1) {
					$arrolnum = min($item->multiloot, $numUsers);

					// Get $arrolnum random values from $item->users
					$winners = (array)array_rand($item->users, $arrolnum);
					$item->users = [];
					$list .= join(
						", ",
						array_map(
							function($name) {
								return "<green>$name<end>";
							},
							$winners
						)
					);

					if ($arrolnum < $item->multiloot) {
						$newmultiloot = $item->multiloot - $arrolnum;
						$this->residual[$resnum] = $item;
						$this->residual[$resnum]->multiloot = $newmultiloot;
						$resnum++;
					}
				} else {
					$winner = array_rand($item->users, 1);
					$list .= "<green>$winner<end>";
				}
				$list .= "\n\n";
			}
		}

		//Reset loot
		$this->loot = [];

		//Show winner list
		if (!empty($this->residual)) {
			$list .= "\n\n".
				$this->text->makeChatcmd("Reroll remaining items", "/tell <myname> reroll");
		}
		$msg = $this->text->makeBlob("Winner List", $list);
		if (!empty($this->residual)) {
			$msg .= " (There are item(s) left to be rolled. To re-add, type <symbol>reroll)";
		}

		$this->chatBot->sendPrivate($msg);
		if ($channel != 'priv') {
			$sendto->reply($msg);
		}
	}

	/**
	 * Add yourself to a loot roll
	 *
	 * @HandlesCommand("add")
	 * @Matches("/^add (\d+)$/i")
	 */
	public function addCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$slot = (int)$args[1];
		$found = false;
		if (count($this->loot) === 0) {
			$this->chatBot->sendTell("No loot list available.", $sender);
			return;
		}
		//Check if the slot exists
		if (!isset($this->loot[$slot])) {
			$msg = "The slot you are trying to add in does not exist.";
			$this->chatBot->sendTell($msg, $sender);
			return;
		}

		//Remove the player from other slots if set
		$found = false;
		foreach ($this->loot as $key => $item) {
			if ($this->loot[$key]->users[$sender] == true) {
				unset($this->loot[$key]->users[$sender]);
				$found = true;
			}
		}

		//Add the player to the chosen slot
		$this->loot[$slot]->users[$sender] = true;

		if ($found === false) {
			$privMsg = "$sender added to <highlight>\"{$this->loot[$slot]->name}\"<end>.";
			$tellMsg = "You added to <highlight>\"{$this->loot[$slot]->name}\"<end>.";
		} else {
			$privMsg = "$sender changed to <highlight>\"{$this->loot[$slot]->name}\"<end>.";
			$tellMsg = "You changedto <highlight>\"{$this->loot[$slot]->name}\"<end>.";
		}

		if ($this->settingManager->get('add_on_loot') & 1) {
			$this->chatBot->sendTell($tellMsg, $sender);
		}
		if ($this->settingManager->get('add_on_loot') & 2) {
			$this->chatBot->sendPrivate($privMsg);
		}
	}
	
	/**
	 * Remove yourself from all loot rolls
	 *
	 * @HandlesCommand("rem")
	 * @Matches("/^rem$/i")
	 */
	public function remCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (count($this->loot) === 0) {
			$this->chatBot->sendTell("There is nothing to remove you from.", $sender);
			return;
		}
		foreach ($this->loot as $key => $item) {
			if ($this->loot[$key]->users[$sender] == true) {
				unset($this->loot[$key]->users[$sender]);
			}
		}

		$player = $this->playerManager->getByName($sender);
		if (!isset($player) || !isset($player->gender) || $player->gender === "Neuter") {
			$privMsg = "$sender removed themselves from all rolls.";
		} elseif ($player->gender === "Female") {
			$privMsg = "$sender removed herself from all rolls.";
		} else {
			$privMsg = "$sender removed himself from all rolls.";
		}
		$tellMsg = "You removed yourself from all rolls.";
		if ($this->settingManager->getInt('add_on_loot') & 1) {
			$this->chatBot->sendTell($tellMsg, $sender);
		}
		if ($this->settingManager->getInt('add_on_loot') & 2) {
			$this->chatBot->sendPrivate($privMsg);
		}
	}

	/**
	 * Get the current loot list
	 *
	 * @return string
	 */
	public function getCurrentLootList(): string {
		if (empty($this->loot)) {
			$msg = "No loot list exists yet.";
			return $msg;
		}

		$flatroll = $this->text->makeChatcmd("<symbol>flatroll", "/tell <myname> flatroll");
		$list = "Use $flatroll to roll.\n\n";
		$players = 0;
		$items = count($this->loot);
		foreach ($this->loot as $key => $item) {
			$add = $this->text->makeChatcmd("Add", "/tell <myname> add $key");
			$rem = $this->text->makeChatcmd("Remove", "/tell <myname> rem");
			$added_players = count($item->users);
			$players += $added_players;

			if ($item->icon !== null && $this->settingManager->getBool('show_loot_pics')) {
				$list .= $this->text->makeImage($item->icon) . "\n";
			}

			$ml = "";
			if ($item->multiloot > 1) {
				$ml = $item->multiloot."x ";
			}

			$list .= "<header2>Slot #$key:<end> {$ml}<highlight>{$item->display}<end>{$item->comment} - $add / $rem";
			if (count($item->users) > 0) {
				$list .= "\n<tab>Players added (<highlight>$added_players<end>): ";
				$list .= join(
					", ",
					array_map(
						function($name) {
							return "<yellow>$name<end>";
						},
						array_keys($item->users)
					)
				);
			}

			$list .= "\n\n";
		}
		$msg = $this->text->makeBlob("Loot List (Items: $items, Players: $players)", $list);

		return $msg;
	}

	/**
	 * Add all items from a raid_loot to the loot list
	 */
	public function addRaidToLootList(string $raid, string $category): bool {
		// clear current loot list
		$this->loot = [];
		$count = 1;

		$sql = "SELECT * FROM raid_loot r ".
			"LEFT JOIN aodb a ON (r.name = a.name AND r.ql >= a.lowql AND r.ql <= a.highql) ".
			"WHERE raid = ? AND category = ?";
		/** @var AODBEntry[] */
		$data = $this->db->fetchAll(AODBEntry::class, $sql, $raid, $category);

		if (count($data) === 0) {
			return false;
		}

		foreach ($data as $row) {
			$item = $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->name);
			if (empty($row->comment)) {
				$row->display = $item;
			} else {
				$row->display = $item . " ($row->comment)";
			}
			$row->users = [];
			$this->loot[$count] = $row;
			$count++;
		}

		return true;
	}

	/**
	 * Get the loot key for the item with the name $name
	 */
	public function getLootItem(string $name): ?int {
		foreach ($this->loot as $key => $item) {
			if ($item->name === $name) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Add an item to the loot roll
	 *
	 * @HandlesCommand("mloot")
	 * @Matches("/^mloot (.+)$/i")
	 */
	public function mlootCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$input = $args[1];
		$syntaxCorrect = preg_match_all(
			"|(<a [^>]*?href=['\"]itemref://\d+/\d+/\d+['\"]>.+?</a>)|",
			$input,
			$matches
		);
		if (!$syntaxCorrect) {
			$sendto->reply("No items were identified. Only item references are supported.");
			return;
		}
		foreach ($matches[1] as $item) {
			$this->addLootItem($item, 1, $sender, true);
		}
		$lootList = $this->getCurrentLootList();
		$this->chatBot->sendPrivate(
			"{$sender} added " . count($matches[1]) . " items to the {$lootList}."
		);
	}
}
