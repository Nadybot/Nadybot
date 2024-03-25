<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PItem,
	ParamClass\PQuantity,
	ParamClass\PRemove,
	Safe,
	Text,
	Util,
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
		command: 'loot',
		accessLevel: 'guest',
		description: 'Show the loot list',
		alias: 'list',
	),
	NCA\DefineCommand(
		command: LootController::CMD_LOOT_MANAGE,
		accessLevel: 'rl',
		description: 'Modify the loot list',
	),
	NCA\DefineCommand(
		command: 'mloot',
		accessLevel: 'rl',
		description: 'Put multiple items on the loot list',
	),
	NCA\DefineCommand(
		command: 'reroll',
		accessLevel: 'rl',
		description: 'Reroll the residual loot list',
	),
	NCA\DefineCommand(
		command: 'flatroll',
		accessLevel: 'rl',
		description: 'Roll the loot list',
		alias: ['rollloot', 'result', 'win'],
	),
	NCA\DefineCommand(
		command: 'add',
		accessLevel: 'guest',
		description: 'Add yourself to a roll slot',
	),
	NCA\DefineCommand(
		command: 'rem',
		accessLevel: 'guest',
		description: 'Remove yourself from a roll slot',
	),
	NCA\DefineCommand(
		command: 'ffa',
		accessLevel: 'rl',
		description: 'Declare the remaining loot FFA',
	),
]
class LootController extends ModuleInstance {
	public const CMD_LOOT_MANAGE = 'loot add/change/delete';
	public const DB_TABLE = 'loot_history_<myname>';

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

	/** Maximum number of entries for loot history and search */
	#[NCA\Setting\Number]
	public int $lootHistoryMaxEntries = 40;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private ItemsController $itemsController;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private ChatLeaderController $chatLeaderController;

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

	private int $roll = 1;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, 'loot addmulti', 'multiloot');
		$this->roll = (int)$this->db->table(self::DB_TABLE)->max('roll') + 1;
	}

	#[NCA\Event(
		name: 'timer(30sec)',
		description: 'Periodically announce running loot rolls'
	)]
	public function announceLootList(): void {
		if (empty($this->loot)) {
			return;
		}
		$lootList = ((array)$this->getCurrentLootList())[0];
		$msg = "\n".
			'<yellow>' . str_repeat('-', 76) . "<end>\n".
			"<tab>There's loot being rolled: {$lootList}\n".
			"<tab>Make sure you've added yourself to a slot if you want something.\n".
			'<yellow>' . str_repeat('-', 76) . '<end>';
		$this->chatBot->sendPrivate($msg);
	}

	/** Show a list of currently rolled loot */
	#[NCA\HandlesCommand('loot')]
	#[NCA\Help\Group('loot')]
	public function lootCommand(CmdContext $context): void {
		$msg = $this->getCurrentLootList();
		$context->reply($msg);
	}

	/** Get a list of the last loot rolls */
	#[NCA\HandlesCommand('loot')]
	#[NCA\Help\Group('loot')]
	public function lootHistoryCommand(
		CmdContext $context,
		#[NCA\Str('history')] string $action,
	): void {
		/** @var Collection<LootHistory> */
		$items = $this->db->table(self::DB_TABLE)
			->orderByDesc('dt')
			->orderBy('pos')
			->limit($this->lootHistoryMaxEntries)
			->asObj(LootHistory::class);
		if ($items->isEmpty()) {
			$context->reply('There are not rolls recorded on this bot.');
			return;
		}
		$compressedList = $this->compressLootHistory($items);
		$rolls = $compressedList->groupBy('roll');
		$lines = $rolls->map(function (Collection $items, int $roll): string {
			/** @var LootHistory */
			$firstItem = $items->firstOrFail();
			$showLink = $this->text->makeChatcmd(
				$items->count() . ' ' . $this->text->pluralize('item', $items->count()),
				"/tell <myname> loot history {$firstItem->roll}"
			);
			return '<tab>' . $this->util->date($firstItem->dt) . ' - '.
				"{$showLink}, rolled by {$firstItem->rolled_by}";
		});
		$msg = 'Last loot rolls (' . $lines->count() . ')';
		$context->reply($this->text->makeBlob(
			$msg,
			"<header2>Last loot rolls<end>\n" . $lines->join("\n")
		));
	}

	/** View what was rolled/won in the given roll */
	#[NCA\HandlesCommand('loot')]
	#[NCA\Help\Group('loot')]
	#[NCA\Help\Example('<symbol>loot show last')]
	#[NCA\Help\Example('<symbol>loot history 17')]
	public function lootShowNumberCommand(
		CmdContext $context,
		#[NCA\StrChoice('show', 'history')] string $action,
		#[NCA\PNumber] #[NCA\Str('last')] string $number,
	): void {
		if (strtolower($number) === 'last') {
			$number = $this->db->table(self::DB_TABLE)->max('roll');
			if ($number < 1) {
				$context->reply('There is no last roll to display.');
				return;
			}
		}
		$roll = (int)$number;

		/** @var Collection<LootHistory> */
		$items = $this->db->table(self::DB_TABLE)
			->where('roll', $roll)
			->orderBy('pos')
			->asObj(LootHistory::class);
		if ($items->isEmpty()) {
			$context->reply("There is no loot roll #<highlight>{$number}<end>.");
			return;
		}
		$compressedList = $this->compressLootHistory($items);
		$lines = $compressedList->map(function (LootHistory $item): string {
			$line = "<header2>Slot #{$item->pos}:<end>";
			if ($item->amount > 1) {
				$line .= " {$item->amount}x";
			}
			$line .= " <highlight>{$item->display}<end>";
			if (isset($item->comment) && strlen($item->comment) && !str_contains($item->display, $item->comment)) {
				$line .= " {$item->comment}";
			}
			$line .= "\n<tab>" . $this->getWinners(...$item->winners);

			return $line;
		});
		$rolledBy = $items->firstOrFail()->rolled_by;
		$rolledTime = $this->util->date($items->firstOrFail()->dt);
		$blob = "Loot #{$roll} was rolled <highlight>{$rolledTime}<end> by <highlight>{$rolledBy}<end>.\n\n";
		$blob .= $lines->join("\n\n");
		$context->reply($this->text->makeBlob(
			"Loot roll #{$roll} (" . $lines->count() . ' slots)',
			$blob
		));
	}

	/**
	 * Search for loot won by &lt;winner&gt;
	 * If 'last' is set, then only the last loot roll with matching items is shown
	 */
	#[NCA\HandlesCommand('loot')]
	#[NCA\Help\Group('loot')]
	public function lootSearchWinnerCommand(
		CmdContext $context,
		#[NCA\Str('search')] string $action,
		#[NCA\Str('last')] ?string $lastOnly,
		#[NCA\Str('winner=')] string $subAction,
		#[NCA\NoSpace] PCharacter $winner,
	): void {
		$items = $this->db->table(self::DB_TABLE)
			->where('winner', $winner())
			->orderByDesc('dt')
			->limit($this->lootHistoryMaxEntries)
			->asObj(LootHistory::class);
		if ($items->isEmpty()) {
			$context->reply("{$winner} hasn't won any items yet.");
			return;
		}
		if (isset($lastOnly)) {
			$items = $items->where('roll', $items->firstOrFail()->roll);
		}
		$lines = $items->map(function (LootHistory $item): string {
			$rollLink = $this->text->makeChatcmd(
				$this->util->date($item->dt),
				"/tell <myname> loot history {$item->roll}"
			);
			$line = "<tab>{$rollLink} - ";
			if ($item->amount > 1) {
				$line .= " 1/{$item->amount}";
			}
			$line .= " {$item->display}";
			if (isset($item->comment) && strlen($item->comment) && !str_contains($item->display, $item->comment)) {
				$line .= " {$item->comment}";
			}
			$line .= " - rolled by {$item->rolled_by}";
			return $line;
		});
		$blob = "<header2>Last items won by {$winner}<end>\n".
			$lines->join("\n");
		$context->reply($this->text->makeBlob("Last items won by {$winner}", $blob));
	}

	/**
	 * Search for winners of loot matching &lt;search&gt;
	 * If 'last' is set, then only the last loot roll with matching items is shown
	 */
	#[NCA\HandlesCommand('loot')]
	#[NCA\Help\Group('loot')]
	public function lootSearchNameCommand(
		CmdContext $context,
		#[NCA\Str('search')] string $action,
		#[NCA\Str('last')] ?string $lastOnly,
		#[NCA\Str('item=')] string $subAction,
		#[NCA\NoSpace] string $search,
	): void {
		$search = trim($search);
		if (strlen($search) < 1) {
			$context->reply('You have to give a string to search for.');
			return;
		}

		/** @var Collection<LootHistory> */
		$items = $this->db->table(self::DB_TABLE)
			->whereIlike('display', "%{$search}%")
			->orderByDesc('dt')
			->orderBy('pos')
			->limit($this->lootHistoryMaxEntries)
			->asObj(LootHistory::class);
		if ($items->isEmpty()) {
			$context->reply(
				"No items were rolled matching your search <highlight>{$search}<end>."
			);
			return;
		}
		if (isset($lastOnly)) {
			$items = $items->where('roll', $items->firstOrFail()->roll);
		}

		$compressedList = $this->compressLootHistory($items);

		$lines = $compressedList->map(function (LootHistory $item): string {
			$rollLink = $this->text->makeChatcmd(
				$this->util->date($item->dt),
				"/tell <myname> loot history {$item->roll}"
			);
			$line = "<tab>{$rollLink} -";
			if ($item->amount > 1) {
				$line .= " {$item->amount}x";
			}
			$line .= " <highlight>{$item->display}<end>";
			if (isset($item->comment) && strlen($item->comment) && !str_contains($item->display, $item->comment)) {
				$line .= " {$item->comment}";
			}
			$line .= " - rolled by {$item->rolled_by}\n".
				'<tab><tab>' . $this->getWinners(...$item->winners);

			return $line;
		});

		$blob = "<header2>Last rolled items matching '{$search}'<end>\n".
			$lines->join("\n");
		$context->reply(
			$this->text->makeBlob("Last rolled items matching '{$search}'", $blob)
		);
	}

	/** Clear the current loot list */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootClearCommand(CmdContext $context, #[NCA\Str('clear')] string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
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

	/** Add an item from a loot list to the loot roll */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootAddByIdCommand(CmdContext $context, #[NCA\Str('add')] string $action, int $id): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
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

		$msg = "{$context->char->name} added <highlight>{$item->name}<end> (x{$item->multiloot}). To add use <symbol>add {$key}.";
		$this->chatBot->sendPrivate($msg);
	}

	/** Auction off an item from a loot list */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootAuctionByIdCommand(CmdContext $context, #[NCA\Str('auction')] string $action, int $id): void {
		$loot = $this->getLootEntryID($id);

		if ($loot === null) {
			$msg = "Could not find item with id <highlight>{$id}<end> to add.";
			$context->reply($msg);
			return;
		}

		$item = $loot->name;
		if (isset($loot->item)) {
			$item = $loot->item->getLink($loot->ql, $loot->name);
		}
		// We want this command to always use the same rights as the bid start
		$context->message = "bid start {$loot->multiloot}x {$item}";
		$this->commandManager->processCmd($context);
	}

	/** Raffle an item from a loot list */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootRaffleByIdCommand(
		CmdContext $context,
		#[NCA\Str('raffle')] string $action,
		int $id
	): void {
		$loot = $this->getLootEntryID($id);

		if ($loot === null) {
			$msg = "Could not find item with id <highlight>{$id}<end> to add.";
			$context->reply($msg);
			return;
		}

		$item = $loot->name;
		if (isset($loot->item)) {
			$item = $loot->item->getLink($loot->ql, $loot->name);
		}
		// We want this command to always use the same rights as the bid start
		$context->message = "raffle add {$loot->multiloot}x {$item}";
		$this->commandManager->processCmd($context);
	}

	/** Add an item to the loot roll by name or by pasting it */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootAddCommand(CmdContext $context, #[NCA\Str('add')] string $action, string $item): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$this->addLootItem($item, 1, $context->char->name);
	}

	/** Add multiple items to the loot roll */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	#[NCA\Help\Example('<symbol>loot addmulti 3 Lockpick')]
	public function multilootCommand(
		CmdContext $context,
		#[NCA\Str('addmulti', 'multiadd')] string $action,
		PQuantity $amount,
		string $items
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$this->addLootItem($items, $amount(), $context->char->name);
	}

	/** Add one item to the loot roll */
	public function addLootItem(string $input, int $multiloot, string $sender, bool $suppressMessage=false): void {
		// Check if the item is a link
		if (count($arr = Safe::pregMatch("|^<a href=['\"]itemref://(\\d+)/(\\d+)/(\\d+)[\"']>(.+)</a>(.*)$|i", $input))) {
			$itemQL = (int)$arr[3];
			$itemHighID = (int)$arr[1];
			$itemLowID = (int)$arr[2];
			$itemName = $arr[4];
		} elseif (count($arr = Safe::pregMatch("|^(.+)<a href=[\"']itemref://(\\d+)/(\\d+)/(\\d+)[\"']>(.+)</a>(.*)$|i", $input))) {
			$itemQL = (int)$arr[4];
			$itemHighID = (int)$arr[2];
			$itemLowID = (int)$arr[3];
			$itemName = $arr[5];
		} else {
			$itemName = $input;
		}

		/** @var ?AODBEntry */
		$row = $this->db->table('aodb')
			->whereIlike('name', $itemName)
			->asObj(AODBEntry::class)
			->first();
		if ($row !== null) {
			$itemName = $row->name;

			// Save the icon
			$looticon = $row->icon;

			// Save the aoid and ql if not set yet
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

		$msg = "{$sender} added <highlight>{$item->display}<end> (x{$item->multiloot}) to Slot <highlight>#{$key}<end>.";
		$msg .= " To add use <symbol>add {$key}, or <symbol>rem to remove yourself.";
		if ($suppressMessage) {
			return;
		}
		$this->chatBot->sendPrivate($msg);
	}

	/** Remove a single item from the loot list */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootRemCommand(CmdContext $context, PRemove $action, int $key): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
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

	/** Create a new loot roll with the leftovers from the last roll */
	#[NCA\HandlesCommand('reroll')]
	#[NCA\Help\Group('loot')]
	public function rerollCommand(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		// Check if a residual list exits
		if (empty($this->residual)) {
			$msg = 'There are no remaining items to re-add.';
			$context->reply($msg);
			return;
		}

		// Re-add remaining loot
		foreach ($this->residual as $key => $item) {
			$this->loot[$key] = $item;
			$this->loot[$key]->added_by = $context->char->name;
		}

		// Reset residual list
		$this->residual = [];
		// Show winner list
		$msg = "All remaining items have been re-added by <highlight>{$context->char->name}<end>. Check <symbol>loot.";
		$this->chatBot->sendPrivate($msg);
		if ($context->isDM()) {
			$context->reply($msg);
		}

		$msg = $this->getCurrentLootList();
		$context->reply($msg);
	}

	/** Announce the remaining loot to be free for all */
	#[NCA\HandlesCommand('ffa')]
	#[NCA\Help\Group('loot')]
	public function ffaCommand(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		// Check if a residual list exits
		if (empty($this->residual)) {
			$msg = 'There are no remaining items to mark ffa.';
			$context->reply($msg);
			return;
		}

		$list = '';
		$numItems = 0;
		foreach ($this->residual as $key => $item) {
			if ($item->icon !== null && $this->showLootPics) {
				$list .= $this->text->makeImage($item->icon) . "\n";
			}

			$ml = '';
			if ($item->multiloot > 1) {
				$ml = $item->multiloot.'x ';
			}
			$numItems += $item->multiloot;

			$list .= "<header2>Slot #{$key}:<end> {$ml}<highlight>{$item->display}<end>";
			if (isset($item->comment) && strlen($item->comment) && !str_contains($item->display, $item->comment)) {
				$list .= " ({$item->comment})";
			}
		}

		// Reset residual list
		$this->residual = [];
		// Create FFA message
		$msg = '';
		if ($numItems > 1) {
			$blob = $this->text->makeBlob('All remaining items', $list, 'These items are FFA');
			$msg = $this->text->blobWrap(
				'',
				$blob,
				" were declared <green>free for all<end> by <highlight>{$context->char->name}."
			);
		} else {
			$blob = $this->text->makeBlob('The remaining item', $list, 'This item is FFA');
			$msg = $this->text->blobWrap(
				'',
				$blob,
				" was declared <green>free for all<end> by <highlight>{$context->char->name}."
			);
		}
		$this->chatBot->sendPrivate($msg);
		if ($context->isDM()) {
			$context->reply($msg);
		}
	}

	/** Determine the winner(s) of the current loot roll */
	#[NCA\HandlesCommand('flatroll')]
	#[NCA\Help\Group('loot')]
	public function flatrollCommand(CmdContext $context): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		// Check if a loot list exits
		if (empty($this->loot)) {
			$msg = 'There is nothing to roll atm.';
			$context->reply($msg);
			return;
		}

		srand(); // get a good seed

		$list = '';
		// Roll the loot
		$resnum = 1;
		foreach ($this->loot as $key => $item) {
			$list .= "Item: <header2>{$item->name}<end>\n";
			$numUsers = count($item->users);
			if ($numUsers == 1) {
				$list .= 'Winner: ';
			} else {
				$list .= 'Winners: ';
			}
			$lootHistory = new LootHistory(
				roll: $this->roll,
				dt: time(),
				pos: $key,
				amount: $item->multiloot,
				added_by: $item->added_by,
				icon: $item->icon,
				name: $item->name,
				display: $item->display,
				comment: $item->comment,
				rolled_by: $context->char->name,
			);
			if ($numUsers === 0) {
				$list .= "<highlight>No one added.<end>\n\n";
				$this->residual[$resnum] = $item;
				$resnum++;
				$lootHistory->winner = null;
				$this->db->insert(self::DB_TABLE, $lootHistory);
			} else {
				/** @psalm-var non-empty-array<string, bool> */
				$users = $item->users;
				if ($item->multiloot > 1) {
					$arrolnum = min($item->multiloot, $numUsers);
					// Get $arrolnum random values from $users
					$winners = (array)array_rand($users, $arrolnum);
					foreach ($winners as $winner) {
						$lootHistory->winner = $winner;
						$this->db->insert(self::DB_TABLE, $lootHistory);
					}
					$item->users = [];
					$list .= implode(
						', ',
						array_map(
							static function ($name) {
								return "<green>{$name}<end>";
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
					$lootHistory->winner = $winner;
					$this->db->insert(self::DB_TABLE, $lootHistory);
					$list .= "<green>{$winner}<end>";
				}
				$list .= "\n\n";
			}
		}

		// Reset loot
		$this->loot = [];
		$this->roll++;

		// Show winner list
		if (!empty($this->residual)) {
			$list .= "\n\n".
				$this->text->makeChatcmd('Reroll remaining items', '/tell <myname> reroll').
				'<tab>'.
				$this->text->makeChatcmd('Announce remaining items FFA', '/tell <myname> ffa');
		}
		$msg = $this->text->makeBlob('Winner List', $list);
		if (!empty($this->residual)) {
			$msg = $this->text->blobWrap(
				'',
				$msg,
				' (There are item(s) left to be rolled. To re-add, type <symbol>reroll, or '.
				'use <symbol>ffa to make them free for all)'
			);
		}

		$this->chatBot->sendPrivate($msg);
		if ($context->isDM()) {
			$context->reply($msg);
		}
	}

	/** Add yourself to a loot roll */
	#[NCA\HandlesCommand('add')]
	#[NCA\Help\Group('loot')]
	public function addCommand(CmdContext $context, int $slot): void {
		$found = false;
		if (count($this->loot) === 0) {
			$this->chatBot->sendMassTell('No loot list available.', $context->char->name);
			return;
		}
		// Check if the slot exists
		if (!isset($this->loot[$slot])) {
			$msg = 'The slot you are trying to add in does not exist.';
			$this->chatBot->sendMassTell($msg, $context->char->name);
			return;
		}

		// Remove the player from other slots if set
		$found = false;
		foreach ($this->loot as $key => $item) {
			if ($this->loot[$key]->users[$context->char->name] == true) {
				unset($this->loot[$key]->users[$context->char->name]);
				$found = true;
			}
		}

		// Add the player to the chosen slot
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

	/** Remove yourself from all loot rolls */
	#[NCA\HandlesCommand('rem')]
	#[NCA\Help\Group('loot')]
	public function remCommand(CmdContext $context): void {
		if (count($this->loot) === 0) {
			$this->chatBot->sendTell('There is nothing to remove you from.', $context->char->name);
			return;
		}
		foreach ($this->loot as $key => $item) {
			if ($this->loot[$key]->users[$context->char->name] == true) {
				unset($this->loot[$key]->users[$context->char->name]);
			}
		}

		$player = $this->playerManager->byName($context->char->name);
		if (!isset($player) || !isset($player->gender) || $player->gender === 'Neuter') {
			$privMsg = "{$context->char->name} removed themselves from all rolls.";
		} elseif ($player->gender === 'Female') {
			$privMsg = "{$context->char->name} removed herself from all rolls.";
		} else {
			$privMsg = "{$context->char->name} removed himself from all rolls.";
		}
		$tellMsg = 'You removed yourself from all rolls.';
		if ($this->addOnLoot & 1) {
			$this->chatBot->sendMassTell($tellMsg, $context->char->name);
		}
		if ($this->addOnLoot & 2) {
			$this->chatBot->sendPrivate($privMsg);
		}
	}

	/**
	 * Get the current loot list
	 *
	 * @return string|string[]
	 */
	public function getCurrentLootList(): string|array {
		if (empty($this->loot)) {
			$msg = 'No loot list exists yet.';
			return $msg;
		}

		$flatroll = $this->text->makeChatcmd('<symbol>flatroll', '/tell <myname> flatroll');
		$list = "Use {$flatroll} to roll.\n\n";
		$players = 0;
		$items = count($this->loot);
		foreach ($this->loot as $key => $item) {
			$add = $this->text->makeChatcmd('add', "/tell <myname> add {$key}");
			$rem = $this->text->makeChatcmd('remove', '/tell <myname> rem');
			$added_players = count($item->users);
			$players += $added_players;

			if ($item->icon !== null && $this->showLootPics) {
				$list .= $this->text->makeImage($item->icon) . "\n";
			}

			$ml = '';
			if ($item->multiloot > 1) {
				$ml = $item->multiloot.'x ';
			}

			$list .= "<header2>Slot #{$key}:<end> {$ml}<highlight>{$item->display}<end>";
			if (isset($item->comment) && strlen($item->comment) && !str_contains($item->display, $item->comment)) {
				$list .= " ({$item->comment})";
			}
			$list .= " - [{$add}] [{$rem}]";
			if (count($item->users) > 0) {
				$list .= "\n<tab>Players added (<highlight>{$added_players}<end>): ";
				$list .= implode(
					', ',
					array_map(
						static function ($name) {
							return "<yellow>{$name}<end>";
						},
						array_keys($item->users)
					)
				);
			}

			$list .= "\n\n";
		}
		$msg = $this->text->makeBlob("Loot List (Items: {$items}, Players: {$players})", $list);

		return $msg;
	}

	/** Add all items from a raid_loot to the loot list */
	public function addRaidToLootList(string $addedBy, string $raid, string $category): bool {
		// clear current loot list
		$this->loot = [];
		$count = 1;

		/** @var Collection<RaidLoot> */
		$data = $this->db->table('raid_loot')
			->where(['raid' => $raid, 'category' => $category])
			->asObj(RaidLoot::class);

		if ($data->count() === 0) {
			return false;
		}

		$itemsByBame =$this->itemsController->getByNames(...$data->pluck('name')->toArray())
			->groupBy('name');
		$data->each(static function (RaidLoot $loot) use ($itemsByBame): void {
			$loot->item = $itemsByBame->get($loot->name)
				->where('lowql', '<=', $loot->ql)
				->where('highql', '>=', $loot->ql)
				->first();
		});

		foreach ($data as $row) {
			$lootItem = new LootItem();
			$lootItem->comment = $row->comment;
			$lootItem->icon = $row->item->icon ?? null;
			$lootItem->multiloot = $row->multiloot;
			$lootItem->name = $row->name;
			$lootItem->added_by = $addedBy;
			$item = $row->name;
			if (isset($row->item)) {
				$item = $row->item->getLink($row->ql);
			}
			if (empty($row->comment)) {
				$lootItem->display = $item;
			} else {
				$lootItem->display = $item . " ({$row->comment})";
			}
			$this->loot[$count] = $lootItem;
			$count++;
		}

		return true;
	}

	/** Get the loot key for the item with the name $name */
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
	#[NCA\HandlesCommand('mloot')]
	#[NCA\Help\Group('loot')]
	public function mlootCommand(CmdContext $context, #[NCA\SpaceOptional] PItem ...$items): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		foreach ($items as $item) {
			$this->addLootItem($item(), 1, $context->char->name, true);
		}
		$lootList = $this->getCurrentLootList();
		$this->chatBot->sendPrivate(
			$this->text->blobWrap(
				"{$context->char->name} added " . count($items) . ' items to the ',
				$lootList,
				'.'
			)
		);
	}

	protected function getLootEntryID(int $id): ?RaidLoot {
		/** @var ?RaidLoot */
		$raidLoot = $this->db->table('raid_loot AS r')
					->where('r.id', $id)
					->asObj(RaidLoot::class)
					->first();
		if (!isset($raidLoot)) {
			return null;
		}
		if (isset($raidLoot->aoid)) {
			$raidLoot->item = $this->itemsController->findById($raidLoot->aoid);
		} else {
			$raidLoot->item = $this->itemsController->getByNames($raidLoot->name)
				->where('lowql', '<=', $raidLoot->ql)
				->where('highql', '>=', $raidLoot->ql)
				->first();
		}
		return $raidLoot;
	}

	private function getWinners(string ...$winners): string {
		$line = 'Winner';
		if (count($winners) !== 1) {
			$line .= 's';
		}
		if (count($winners) === 0) {
			$line .= ': &lt;No one added&gt;';
		} else {
			$winners = $this->text->arraySprintf('<green>%s<end>', ...$winners);
			$line .= ': ' . $this->text->enumerate(...$winners);
		}
		return $line;
	}

	/**
	 * @param Collection<LootHistory> $items
	 *
	 * @return Collection<LootHistory>
	 */
	private function compressLootHistory(Collection $items): Collection {
		$lastRoll = 0;
		$lastPos = 0;

		/** @var Collection<LootHistory> */
		$compressedList = new Collection();
		foreach ($items as $item) {
			if ($lastRoll === $item->roll && $lastPos === $item->pos) {
				if (!isset($item->winner)) {
					continue;
				}
				$compressedList->last()->winners []= $item->winner;
				continue;
			}
			if (isset($item->winner)) {
				$item->winners = [$item->winner];
			}
			$compressedList->push($item);
			$lastRoll = $item->roll;
			$lastPos = $item->pos;
		}
		return $compressedList;
	}
}
