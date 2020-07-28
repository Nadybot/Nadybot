<?php

namespace Budabot\Modules\RAID_MODULE;

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
class RaidController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\Modules\PLAYER_LOOKUP\PlayerManager
	 * @Inject
	 */
	public $playerManager;

	/**
	 * @var \Budabot\Core\CommandAlias $commandAlias
	 * @Inject
	 */
	public $commandAlias;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * @var \Budabot\Modules\BASIC_CHAT_MODULE\ChatLeaderController $chatLeaderController
	 * @Inject
	 */
	public $chatLeaderController;

	/**
	 * The currently rolled items
	 *
	 * @var LootItem[]
	 */
	private $loot = array();

	/**
	 * The leftovers from the last loot roll
	 *
	 * @var LootItem[]
	 */
	private $residual = array();

	/**
	 * @Setup
	 */
	public function setup() {
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
			'yes;no',
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
	public function announceLootList() {
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
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function lootCommand($message, $channel, $sender, $sendto, $args) {
		$msg = $this->getCurrentLootList();
		$sendto->reply($msg);
	}

	/**
	 * Clear the current loot list
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot clear$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function lootClearCommand($message, $channel, $sender, $sendto, $args) {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->loot = array();
		$this->residual = array();
		$msg = "Loot has been cleared by <highlight>$sender<end>.";
		$this->chatBot->sendPrivate($msg);

		if ($channel != 'priv') {
			$sendto->reply($msg);
		}
	}

	/**
	 * Add an item from the raid_loot to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot add ([0-9]+)$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function lootAddByIdCommand($message, $channel, $sender, $sendto, $args) {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$id = $args[1];

		$sql = "SELECT *, COALESCE(a.name, r.name) AS name ".
			"FROM raid_loot r ".
			"LEFT JOIN aodb a ON (r.name = a.name AND r.ql >= a.lowql AND r.ql <= a.highql) ".
			"WHERE id = ?";
		$row = $this->db->queryRow($sql, $id);

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
	 * Add an item to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot add (.+)$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function lootAddCommand($message, $channel, $sender, $sendto, $args) {
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
	 * @Matches("/^loot addmulti ([0-9]+)x? (.+)$/i")
	 * @Matches("/^loot multiadd ([0-9]+)x? (.+)$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function multilootCommand($message, $channel, $sender, $sendto, $args) {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$multiloot = $args[1];
		$input = $args[2];
		$this->addLootItem($input, $multiloot, $sender);
	}

	/**
	 * Add one item to the loot roll
	 *
	 * @param string $input     The item as given by the player
	 * @param int    $multiloot How many items to add
	 * @param string $sender    The name of the player adding the item
	 * @return void
	 */
	public function addLootItem($input, $multiloot, $sender) {
		//Check if the item is a link
		if (preg_match("|^<a href=\"itemref://(\\d+)/(\\d+)/(\\d+)\">(.+)</a>(.*)$|i", $input, $arr)) {
			$item_ql = $arr[3];
			$item_highid = $arr[1];
			$item_lowid = $arr[2];
			$item_name = $arr[4];
		} elseif (preg_match("|^(.+)<a href=\"itemref://(\\d+)/(\\d+)/(\\d+)\">(.+)</a>(.*)$|i", $input, $arr)) {
			$item_ql = $arr[4];
			$item_highid = $arr[2];
			$item_lowid = $arr[3];
			$item_name = $arr[5];
		} else {
			$item_name = $input;
		}

		// check if there is an icon available
		$row = $this->db->queryRow("SELECT * FROM aodb WHERE `name` LIKE ?", $item_name);
		if ($row !== null) {
			$item_name = $row->name;

			//Save the icon
			$looticon = $row->icon;

			//Save the aoid and ql if not set yet
			if (!isset($item_highid)) {
				$item_lowid = $row->lowid;
				$item_highid = $row->highid;
				$item_ql = $row->highql;
			}
		}

		// check if the item is already on the list
		$key = $this->getLootItem($item_name);
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

			$item->name = $item_name;
			$item->icon = $looticon;
			$item->added_by = $sender;
			$item->multiloot = $multiloot;

			if (isset($item_highid)) {
				$item->display = $this->text->makeItem($item_lowid, $item_highid, $item_ql, $item_name);
			} else {
				$item->display = $item_name;
			}
			$this->loot[$key] = $item;
		}

		$msg = "$sender added <highlight>{$item->name}<end> (x$item->multiloot) to Slot <highlight>#$key<end>.";
		$msg .= " To add use <symbol>add $key, or <symbol>rem to remove yourself.";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Remove a single item from the loot list
	 *
	 * @HandlesCommand("loot .+")
	 * @Matches("/^loot rem ([0-9]+)$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function lootRemCommand($message, $channel, $sender, $sendto, $args) {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$key = $args[1];
		// validate item existance on loot list
		if ($key > 0 && $key <= count($this->loot)) {
			// if removing this item empties the list, clear the loot list properly
			if (count($this->loot) <= 1) {
				$this->loot = array();
				$this->chatBot->sendPrivate("Item in slot <highlight>#".$key."<end> was the last item in the list. The list has been cleared.");
			} else {
				// remove the item by shifting lower items up one slot and remove last slot
				$loop = $key;
				while ($loop < count($this->loot)) {
					$this->loot[$loop] = $this->loot[$loop+1];
					$loop++;
				}
				unset($this->loot[count($this->loot)]);
				$this->chatBot->sendPrivate("Removing item in slot <highlight>#".$key."<end>");
			}
		} else {
			$this->chatBot->sendPrivate("There is no item at slot <highlight>#".$key."<end>");
		}
	}

	/**
	 * Create a new loot roll with the leftovers from the last roll
	 *
	 * @HandlesCommand("reroll")
	 * @Matches("/^reroll$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function rerollCommand($message, $channel, $sender, $sendto, $args) {
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
		$this->residual = array();
		//Show winner list
		$msg = "All remaining items have been re-added by <highlight>$sender<end>. Check <symbol>loot.";
		$this->chatBot->sendPrivate($msg);
		if ($channel != 'priv') {
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
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function flatrollCommand($message, $channel, $sender, $sendto, $args) {
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
					$item->users = array();
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
		$this->loot = array();

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
	 * @Matches("/^add ([0-9]+)$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function addCommand($message, $channel, $sender, $sendto, $args) {
		$slot = $args[1];
		$found = false;
		if (count($this->loot) > 0) {
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

			if ($found == false) {
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
		} else {
			$this->chatBot->sendTell("No loot list available.", $sender);
		}
	}
	
	/**
	 * Remove yourself from all loot rolls
	 *
	 * @HandlesCommand("rem")
	 * @Matches("/^rem$/i")
	 *
	 * @param string $message The raw message as received by the bot
	 * @param string $channel Where the message was received (org, priv, tell)
	 * @param string $sender Name of the player sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to use for replying to
	 * @param string[] $args The parsed arguments from the Matches regexp
	 * @return void
	 */
	public function remCommand($message, $channel, $sender, $sendto, $args) {
		if (count($this->loot) > 0) {
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
			if ($this->settingManager->get('add_on_loot') & 1) {
				$this->chatBot->sendTell($tellMsg, $sender);
			}
			if ($this->settingManager->get('add_on_loot') & 2) {
				$this->chatBot->sendPrivate($privMsg);
			}
		} else {
			$this->chatBot->sendTell("There is nothing to remove you from.", $sender);
		}
	}

	/**
	 * Get the current loot list
	 *
	 * @return string
	 */
	public function getCurrentLootList() {
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

			if ($item->icon != "" && $this->settingManager->get('show_loot_pics')) {
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
	 *
	 * @param string $raid     Name of the raid
	 * @param string $category Name of the category in the raid
	 * @return bool
	 */
	public function addRaidToLootList($raid, $category) {
		// clear current loot list
		$this->loot = array();
		$count = 1;

		$sql = "SELECT * FROM raid_loot r ".
			"LEFT JOIN aodb a ON (r.name = a.name AND r.ql >= a.lowql AND r.ql <= a.highql) ".
			"WHERE raid = ? AND category = ?";
		$data = $this->db->query($sql, $raid, $category);

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
			$row->users = array();
			$this->loot[$count] = $row;
			$count++;
		}

		return true;
	}

	/**
	 * Get the loot key for the item with the name $name
	 *
	 * @param string $name Name of the item (e.g. "Nanodeck Activation Device")
	 * @return string|null
	 */
	public function getLootItem($name) {
		foreach ($this->loot as $key => $item) {
			if ($item->name == $name) {
				return $key;
			}
		}
		return null;
	}
}
