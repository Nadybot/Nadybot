<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	DBSchema\Player,
	ModuleInstance,
	Nadybot,
	ParamClass\PItem,
	ParamClass\PQuantity,
	ParamClass\PRemove,
	Text,
	Modules\PLAYER_LOOKUP\PlayerManager,
};
use Nadybot\Modules\{
	BASIC_CHAT_MODULE\ChatLeaderController,
	ITEMS_MODULE\AODBEntry,
	ITEMS_MODULE\ItemsController,
};

/**
 * @author Derroylo (RK2)
 * @author Marinerecon (RK2)
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "loot",
		accessLevel: "guest",
		description: "Show the loot list",
		alias: 'list',
	),
	NCA\DefineCommand(
		command: LootController::CMD_LOOT_MANAGE,
		accessLevel: "rl",
		description: "Modify the loot list",
	),
	NCA\DefineCommand(
		command: "mloot",
		accessLevel: "rl",
		description: "Put multiple items on the loot list",
	),
	NCA\DefineCommand(
		command: "reroll",
		accessLevel: "rl",
		description: "Reroll the residual loot list",
	),
	NCA\DefineCommand(
		command: "flatroll",
		accessLevel: "rl",
		description: "Roll the loot list",
		alias: ['rollloot', 'result', 'win'],
	),
	NCA\DefineCommand(
		command: "add",
		accessLevel: "guest",
		description: "Add yourself to a roll slot",
	),
	NCA\DefineCommand(
		command: "rem",
		accessLevel: "guest",
		description: "Remove yourself from a roll slot",
	),
]
class LootController extends ModuleInstance {
	public const CMD_LOOT_MANAGE = "loot add/change/delete";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public CommandManager $commandManager;
	#
	#[NCA\Inject]
	public ItemsController $itemsController;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public ChatLeaderController $chatLeaderController;

	/** Confirmation messages for adding to loot */
	#[NCA\Setting\Options(options: [
		'tells' => 1,
		'privatechat' => 2,
		'privatechat and tells' => 3,
	])]
	public int $addOnLoot = 2;

	/** Show pictures in loot-command */
	#[NCA\Setting\Boolean]
	public bool $showLootPics = true;

	/**
	 * The currently rolled items
	 * @var LootItem[]
	 */
	private $loot = [];

	/**
	 * The leftovers from the last loot roll
	 * @var LootItem[]
	 */
	private $residual = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "loot addmulti", "multiloot");
	}

	#[NCA\Event(
		name: "timer(30sec)",
		description: "Periodically announce running loot rolls"
	)]
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
	 */
	#[NCA\HandlesCommand("loot")]
	#[NCA\Help\Group("loot")]
	public function lootCommand(CmdContext $context): void {
		$msg = $this->getCurrentLootList();
		$context->reply($msg);
	}

	/**
	 * Clear the current loot list
	 */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group("loot")]
	public function lootClearCommand(CmdContext $context, #[NCA\Str("clear")] string $action): void {
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

	protected function getLootEntryID(int $id): ?RaidLootSearch {
		/** @var ?RaidLootSearch */
		$raidLoot = $this->db->table("raid_loot AS r")
					->where("r.id", $id)
					->asObj(RaidLootSearch::class)
					->first();
		if (!isset($raidLoot)) {
			return null;
		}
		if (isset($raidLoot->aoid)) {
			$raidLoot->item = $this->itemsController->findById($raidLoot->aoid);
		} else {
			$raidLoot->item = $this->itemsController->getByNames($raidLoot->name)
				->where("lowql", "<=", $raidLoot->ql)
				->where("highql", ">=", $raidLoot->ql)
				->first();
		}
		return $raidLoot;
	}

	/**
	 * Add an item from a loot list to the loot roll
	 */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group("loot")]
	public function lootAddByIdCommand(CmdContext $context, #[NCA\Str("add")] string $action, int $id): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$loot = $this->getLootEntryID($id);

		if ($loot === null) {
			$msg = "Could not find item with id <highlight>{$id}<end> to add.";
			$context->reply($msg);
			return;
		}

		$key = $this->getLootItem($loot->name);
		if ($key !== null) {
			$item = $this->loot[$key];
			$item->multiloot += $loot->multiloot;
		} else {
			if (!empty($this->loot)) {
				$key = count($this->loot) + 1;
			} else {
				$key = 1;
			}

			$item = new LootItem();

			$item->name = $loot->name;
			$item->icon = null;
			$item->added_by = $context->char->name;
			$item->display = $loot->name;
			if (isset($loot->item)) {
				$item->display = $loot->item->getLink($loot->ql, $loot->name);
				$item->icon = $loot->item->icon;
			}
			$item->comment = $loot->comment;
			$item->multiloot = $loot->multiloot;

			$this->loot[$key] = $item;
		}

		$msg = "{$context->char->name} added <highlight>{$item->name}<end> (x$item->multiloot). To add use <symbol>add {$key}.";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Auction off an item from a loot list
	 */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group("loot")]
	public function lootAuctionByIdCommand(CmdContext $context, #[NCA\Str("auction")] string $action, int $id): void {
		$loot = $this->getLootEntryID($id);

		if ($loot === null) {
			$msg = "Could not find item with id <highlight>$id<end> to add.";
			$context->reply($msg);
			return;
		}

		$item = $loot->name;
		if (isset($loot->item)) {
			$item = $loot->item->getLink($loot->ql, $loot->name);
		}
		// We want this command to always use the same rights as the bid start
		$context->message = "bid start {$item}";
		$this->commandManager->processCmd($context);
	}

	/**
	 * Add an item to the loot roll by name or by pasting it
	 */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group("loot")]
	public function lootAddCommand(CmdContext $context, #[NCA\Str("add")] string $action, string $item): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->addLootItem($item, 1, $context->char->name);
	}

	/**
	 * Add multiple items to the loot roll
	 */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group("loot")]
	#[NCA\Help\Example("<symbol>loot addmulti 3 Lockpick")]
	public function multilootCommand(
		CmdContext $context,
		#[NCA\Str("addmulti", "multiadd")] string $action,
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
	public function addLootItem(string $input, int $multiloot, string $sender, bool $suppressMessage=false): void {
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
		if ($suppressMessage) {
			return;
		}
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * Remove a single item from the loot list
	 */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group("loot")]
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
	 */
	#[NCA\HandlesCommand("reroll")]
	#[NCA\Help\Group("loot")]
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
	 */
	#[NCA\HandlesCommand("flatroll")]
	#[NCA\Help\Group("loot")]
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
			if ($numUsers === 0) {
				$list .= "<highlight>No one added.<end>\n\n";
				$this->residual[$resnum] = $item;
				$resnum++;
			} else {
				/** @psalm-var non-empty-array<string, bool> */
				$users = $item->users;
				if ($item->multiloot > 1) {
					$arrolnum = min($item->multiloot, $numUsers);

					// Get $arrolnum random values from $users
					$winners = (array)array_rand($users, $arrolnum);
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
					$winner = array_rand($users, 1);
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
	 */
	#[NCA\HandlesCommand("add")]
	#[NCA\Help\Group("loot")]
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

		if ($this->addOnLoot & 1) {
			$this->chatBot->sendMassTell($tellMsg, $context->char->name);
		}
		if ($this->addOnLoot & 2) {
			$this->chatBot->sendPrivate($privMsg);
		}
	}

	/**
	 * Remove yourself from all loot rolls
	 */
	#[NCA\HandlesCommand("rem")]
	#[NCA\Help\Group("loot")]
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
				if ($this->addOnLoot & 1) {
					$this->chatBot->sendMassTell($tellMsg, $context->char->name);
				}
				if ($this->addOnLoot & 2) {
					$this->chatBot->sendPrivate($privMsg);
				}
			},
			$context->char->name
		);
	}

	/**
	 * Get the current loot list
	 * @return string|string[]
	 */
	public function getCurrentLootList(): string|array {
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

			if ($item->icon !== null && $this->showLootPics) {
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

		/** @var Collection<RaidLoot> */
		$data = $this->db->table("raid_loot")
			->where(["raid" => $raid, "category" => $category])
			->asObj(RaidLoot::class);

		if ($data->count() === 0) {
			return false;
		}

		$itemsByBame =$this->itemsController->getByNames(...$data->pluck("name")->toArray())
			->groupBy("name");
		$data->each(function (RaidLoot $loot) use ($itemsByBame): void {
			$loot->item = $itemsByBame->get($loot->name)
				->where("lowql", "<=", $loot->ql)
				->where("highql", ">=", $loot->ql)
				->first();
		});

		foreach ($data as $row) {
			$lootItem = new LootItem();
			$lootItem->comment = $row->comment;
			$lootItem->icon = $row->item->icon ?? null;
			$lootItem->multiloot = $row->multiloot;
			$lootItem->name = $row->name;
			$item = $row->name;
			if (isset($row->item)) {
				$item = $row->item->getLink($row->ql);
			}
			if (empty($row->comment)) {
				$lootItem->display = $item;
			} else {
				$lootItem->display = $item . " ($row->comment)";
			}
			$this->loot[$count] = $lootItem;
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
	 * Add one or more items to the loot roll by just pasting them, one after the other
	 * This can be used as loot command for AOIA
	 */
	#[NCA\HandlesCommand("mloot")]
	#[NCA\Help\Group("loot")]
	public function mlootCommand(CmdContext $context, #[NCA\SpaceOptional] PItem ...$items): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		foreach ($items as $item) {
			$this->addLootItem($item(), 1, $context->char->name, true);
		}
		$lootList = $this->getCurrentLootList();
		$this->chatBot->sendPrivate(
			$this->text->blobWrap(
				"{$context->char->name} added " . count($items) . " items to the ",
				$lootList,
				"."
			)
		);
	}
}
