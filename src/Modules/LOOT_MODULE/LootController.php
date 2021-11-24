<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	DBRow,
	Nadybot,
	SettingManager,
	Text,
	Modules\PLAYER_LOOKUP\PlayerManager,
};
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\ParamClass\PItem;
use Nadybot\Core\ParamClass\PQuantity;
use Nadybot\Core\ParamClass\PRemove;
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
		$lootList = ((array)$this->getCurrentLootList())[0];
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
	 */
	public function lootCommand(CmdContext $context): void {
		$msg = $this->getCurrentLootList();
		$context->reply($msg);
	}

	/**
	 * Clear the current loot list
	 *
	 * @HandlesCommand("loot .+")
	 * @Mask $action clear
	 */
	public function lootClearCommand(CmdContext $context, string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->loot = [];
		$this->residual = [];
		$msg = "Loot has been cleared by <highlight>{$context->char->name}<end>.";
		$this->chatBot->sendPrivate($msg);

		if ($context->isDM()) {
			$context->reply($msg);
		}
	}

	protected function getLootEntryID(int $id): ?DBRow {
		$query2 = $this->db->table("raid_loot AS r")
					->join("aodb AS a", "r.aoid", "a.highid")
					->whereNotNull("r.aoid")
					->where("r.id", $id);
		$query2->select("*", $query2->colFunc("COALESCE", ["a.name", "r.name"], "name"));
		$query = $this->db->table("raid_loot AS r")
			->leftJoin("aodb AS a", function (JoinClause $join) {
				$join->on("r.name", "a.name")
					->on("r.ql", ">=", "a.lowql")
					->on("r.ql", "<=", "a.highql");
			})
			->whereNull("r.aoid")
			->where("r.id", $id)
			->union($query2);
		$query->select("*", $query2->colFunc("COALESCE", ["a.name", "r.name"], "name"));
		return $query->asObj()->first();
	}

	/**
	 * Add an item from the raid_loot to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Mask $action add
	 */
	public function lootAddByIdCommand(CmdContext $context, string $action, int $id): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$row = $this->getLootEntryID($id);

		if ($row === null) {
			$msg = "Could not find item with id <highlight>{$id}<end> to add.";
			$context->reply($msg);
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
			$item->added_by = $context->char->name;
			$item->display = $row->name;
			if ($row->lowid) {
				$item->display = $this->text->makeItem($row->lowid, $row->highid, $row->ql, $row->name);
			}
			$item->comment = $row->comment;
			$item->multiloot = $row->multiloot;

			$this->loot[$key] = $item;
		}

		$msg = "{$context->char->name} added <highlight>{$item->name}<end> (x$item->multiloot). To add use <symbol>add $key.";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Add an item from the raid_loot to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Mask $action auction
	 */
	public function lootAuctionByIdCommand(CmdContext $context, string $action, int $id): void {
		$row = $this->getLootEntryID($id);

		if ($row === null) {
			$msg = "Could not find item with id <highlight>$id<end> to add.";
			$context->reply($msg);
			return;
		}

		$item = $row->name;
		if ($row->lowid) {
			$item = $this->text->makeItem((int)$row->lowid, $row->highid, $row->ql, $row->name);
		}
		// We want this command to always use the same rights as the bid start
		$context->message = "bid start {$item}";
		$this->commandManager->processCmd($context);
	}

	/**
	 * Add an item to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Mask $action add
	 */
	public function lootAddCommand(CmdContext $context, string $action, string $item): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addLootItem($item, 1, $context->char->name);
	}

	/**
	 * Add multiple items to the loot roll
	 *
	 * @HandlesCommand("loot .+")
	 * @Mask $action (addmulti|multiadd)
	 */
	public function multilootCommand(
		CmdContext $context,
		string $action,
		PQuantity $amount,
		string $items
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addLootItem($items, $amount(), $context->char->name);
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
		$row = $this->db->table("aodb")
			->whereIlike("name", $itemName)
			->asObj(AODBEntry::class)
			->first();
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
			$item->icon = $looticon??null;
			$item->added_by = $sender;
			$item->multiloot = $multiloot;

			if (isset($itemHighID)) {
				$item->display = $this->text->makeItem($itemLowID??$itemHighID, $itemHighID, $itemQL??1, $itemName);
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
	 */
	public function lootRemCommand(CmdContext $context, PRemove $action, int $key): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		// validate item existence on loot list
		if ($key === 0 || $key > count($this->loot)) {
			$context->reply("There is no item at slot <highlight>#{$key}<end>.");
			return;
		}
		// if removing this item empties the list, clear the loot list properly
		if (count($this->loot) <= 1) {
			$this->loot = [];
			$this->chatBot->sendPrivate("Item in slot <highlight>#{$key}<end> was the last item in the list. The list has been cleared.");
			return;
		}
		// remove the item by shifting lower items up one slot and remove last slot
		$loop = $key;
		while ($loop < count($this->loot)) {
			$this->loot[$loop] = $this->loot[$loop+1];
			$loop++;
		}
		unset($this->loot[count($this->loot)]);
		$this->chatBot->sendPrivate("Removing item in slot <highlight>#{$key}<end>.");
	}

	/**
	 * Create a new loot roll with the leftovers from the last roll
	 *
	 * @HandlesCommand("reroll")
	 */
	public function rerollCommand(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		//Check if a residual list exits
		if (empty($this->residual)) {
			$msg = "There are no remaining items to re-add.";
			$context->reply($msg);
			return;
		}

		// Re-add remaining loot
		foreach ($this->residual as $key => $item) {
			$this->loot[$key] = $item;
			$this->loot[$key]->added_by = $context->char->name;
		}

		//Reset residual list
		$this->residual = [];
		//Show winner list
		$msg = "All remaining items have been re-added by <highlight>{$context->char->name}<end>. Check <symbol>loot.";
		$this->chatBot->sendPrivate($msg);
		if ($context->isDM()) {
			$context->reply($msg);
		}

		$msg = $this->getCurrentLootList();
		$context->reply($msg);
	}

	/**
	 * Determine the winner(s) of the current loot roll
	 *
	 * @HandlesCommand("flatroll")
	 */
	public function flatrollCommand(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		//Check if a loot list exits
		if (empty($this->loot)) {
			$msg = "There is nothing to roll atm.";
			$context->reply($msg);
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
			$msg = $this->text->blobWrap(
				"",
				$msg,
				" (There are item(s) left to be rolled. To re-add, type <symbol>reroll)"
			);
		}

		$this->chatBot->sendPrivate($msg);
		if ($context->isDM()) {
			$context->reply($msg);
		}
	}

	/**
	 * Add yourself to a loot roll
	 *
	 * @HandlesCommand("add")
	 */
	public function addCommand(CmdContext $context, int $slot): void {
		$found = false;
		if (count($this->loot) === 0) {
			$this->chatBot->sendMassTell("No loot list available.", $context->char->name);
			return;
		}
		//Check if the slot exists
		if (!isset($this->loot[$slot])) {
			$msg = "The slot you are trying to add in does not exist.";
			$this->chatBot->sendMassTell($msg, $context->char->name);
			return;
		}

		//Remove the player from other slots if set
		$found = false;
		foreach ($this->loot as $key => $item) {
			if ($this->loot[$key]->users[$context->char->name] == true) {
				unset($this->loot[$key]->users[$context->char->name]);
				$found = true;
			}
		}

		//Add the player to the chosen slot
		$this->loot[$slot]->users[$context->char->name] = true;

		if ($found === false) {
			$privMsg = "{$context->char->name} added to <highlight>\"{$this->loot[$slot]->name}\"<end>.";
			$tellMsg = "You added to <highlight>\"{$this->loot[$slot]->name}\"<end>.";
		} else {
			$privMsg = "{$context->char->name} changed to <highlight>\"{$this->loot[$slot]->name}\"<end>.";
			$tellMsg = "You changedto <highlight>\"{$this->loot[$slot]->name}\"<end>.";
		}

		if (($this->settingManager->getInt('add_on_loot')??2) & 1) {
			$this->chatBot->sendMassTell($tellMsg, $context->char->name);
		}
		if (($this->settingManager->getInt('add_on_loot')??2) & 2) {
			$this->chatBot->sendPrivate($privMsg);
		}
	}

	/**
	 * Remove yourself from all loot rolls
	 *
	 * @HandlesCommand("rem")
	 */
	public function remCommand(CmdContext $context): void {
		if (count($this->loot) === 0) {
			$this->chatBot->sendTell("There is nothing to remove you from.", $context->char->name);
			return;
		}
		foreach ($this->loot as $key => $item) {
			if ($this->loot[$key]->users[$context->char->name] == true) {
				unset($this->loot[$key]->users[$context->char->name]);
			}
		}

		$this->playerManager->getByNameAsync(
			function(?Player $player) use ($context): void {
				if (!isset($player) || !isset($player->gender) || $player->gender === "Neuter") {
					$privMsg = "{$context->char->name} removed themselves from all rolls.";
				} elseif ($player->gender === "Female") {
					$privMsg = "{$context->char->name} removed herself from all rolls.";
				} else {
					$privMsg = "{$context->char->name} removed himself from all rolls.";
				}
				$tellMsg = "You removed yourself from all rolls.";
				if (($this->settingManager->getInt('add_on_loot')??2) & 1) {
					$this->chatBot->sendMassTell($tellMsg, $context->char->name);
				}
				if (($this->settingManager->getInt('add_on_loot')??2) & 2) {
					$this->chatBot->sendPrivate($privMsg);
				}
			},
			$context->char->name
		);
	}

	/**
	 * Get the current loot list
	 *
	 * @return string|string[]
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
			$add = $this->text->makeChatcmd("add", "/tell <myname> add $key");
			$rem = $this->text->makeChatcmd("remove", "/tell <myname> rem");
			$added_players = count($item->users);
			$players += $added_players;

			if ($item->icon !== null && $this->settingManager->getBool('show_loot_pics')) {
				$list .= $this->text->makeImage($item->icon) . "\n";
			}

			$ml = "";
			if ($item->multiloot > 1) {
				$ml = $item->multiloot."x ";
			}

			$list .= "<header2>Slot #$key:<end> {$ml}<highlight>{$item->display}<end>";
			if (isset($item->comment) && strlen($item->comment) && strpos($item->display, $item->comment) === false) {
				$list .= " ({$item->comment})";
			}
			$list .= " - [$add] [$rem]";
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

		/** @var Collection<AODBEntry> */
		$data = $this->db->table("raid_loot AS r")
			->leftJoin("aodb AS a", function (JoinClause $join) {
				$join->on("r.name", "a.name")
					->on("r.ql", ">=", "a.lowql")
					->on("r.ql", "<=", "a.highql");
			})
			->where(["raid" => $raid, "category" => $category])
			->asObj(AODBEntry::class);

		if ($data->count() === 0) {
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
	 * @SpaceOptional $loot
	 */
	public function mlootCommand(CmdContext $context, PItem ...$loot): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		foreach ($loot as $item) {
			$this->addLootItem($item(), 1, $context->char->name, true);
		}
		$lootList = $this->getCurrentLootList();
		$this->chatBot->sendPrivate(
			$this->text->blobWrap(
				"{$context->char->name} added " . count($loot) . " items to the ",
				$lootList,
				"."
			)
		);
	}
}
