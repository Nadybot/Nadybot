<?php declare(strict_types=1);

namespace Nadybot\Modules\LOOT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Parameter\NoSpace,
	Attributes\Parameter\NumberStr,
	Attributes\Parameter\Quantity,
	Attributes\Parameter\Remove,
	Attributes\Parameter\SpaceOptional,
	Attributes\Parameter\Str,
	Attributes\Parameter\StrChoice,
	CmdContext,
	CommandAlias,
	CommandManager,
	DB,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PItem,
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

	/** Confirmation messages for adding to loot */
	#[NCA\Setting\Options(options: [
		'tells' => 1,
		'privatechat' => 2,
		'privatechat and tells' => 3,
	])]
	public int $addOnLoot = 2;

	/** Text to show on items where no one added */
	#[NCA\Setting\Text(options: [
		'-',
		'None',
		'No one added',
	])]
	public string $noOneAddedName = '-';

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
	private ChatLeaderController $chatLeaderController;

	/**
	 * The currently rolled items
	 *
	 * @var LootItem[]
	 */
	private array $loot = [];

	/**
	 * The leftovers from the last loot roll
	 *
	 * @var LootItem[]
	 */
	private array $residual = [];

	private int $roll = 1;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, 'loot addmulti', 'multiloot');
		$this->roll = (int)$this->db->table(LootHistory::getTable())->max('roll') + 1;
	}

	#[NCA\HandlesEvent(
		name: 'timer(30sec)',
		description: 'Periodically announce running loot rolls'
	)]
	public function announceLootList(): void {
		if (!count($this->loot)) {
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
		#[Str('history')] string $action,
	): void {
		$items = $this->db->table(LootHistory::getTable())
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
		$lines = $rolls->map(static function (Collection $items, int $roll): string {
			/** @var LootHistory */
			$firstItem = $items->firstOrFail();
			$showLink = Text::makeChatcmd(
				$items->count() . ' ' . Text::pluralize('item', $items->count()),
				"/tell <myname> loot history {$firstItem->roll}"
			);
			return '<tab>' . Util::date($firstItem->dt) . ' - '.
				"{$showLink}, rolled by {$firstItem->rolled_by}";
		});
		$msg = 'Last loot rolls (' . $lines->count() . ')';
		$context->reply(Text::makeBlob(
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
		#[StrChoice('show', 'history')] string $action,
		#[NumberStr] #[Str('last')] string $number,
	): void {
		if (strtolower($number) === 'last') {
			$number = $this->db->table(LootHistory::getTable())->max('roll');
			if ($number < 1) {
				$context->reply('There is no last roll to display.');
				return;
			}
		}
		$roll = (int)$number;

		$items = $this->db->table(LootHistory::getTable())
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
		$rolledTime = Util::date($items->firstOrFail()->dt);
		$blob = "Loot #{$roll} was rolled <highlight>{$rolledTime}<end> by <highlight>{$rolledBy}<end>.\n\n";
		$blob .= $lines->join("\n\n");
		$context->reply(Text::makeBlob(
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
		#[Str('search')] string $action,
		#[Str('last')] ?string $lastOnly,
		#[Str('winner=')] string $subAction,
		#[NoSpace] PCharacter $winner,
	): void {
		$items = $this->db->table(LootHistory::getTable())
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
		$lines = $items->map(static function (LootHistory $item): string {
			$rollLink = Text::makeChatcmd(
				Util::date($item->dt),
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
		$context->reply(Text::makeBlob("Last items won by {$winner}", $blob));
	}

	/**
	 * Search for winners of loot matching &lt;search&gt;
	 * If 'last' is set, then only the last loot roll with matching items is shown
	 */
	#[NCA\HandlesCommand('loot')]
	#[NCA\Help\Group('loot')]
	public function lootSearchNameCommand(
		CmdContext $context,
		#[Str('search')] string $action,
		#[Str('last')] ?string $lastOnly,
		#[Str('item=')] string $subAction,
		#[NoSpace] string $search,
	): void {
		$search = trim($search);
		if (strlen($search) < 1) {
			$context->reply('You have to give a string to search for.');
			return;
		}

		$items = $this->db->table(LootHistory::getTable())
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
			$rollLink = Text::makeChatcmd(
				Util::date($item->dt),
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
			Text::makeBlob("Last rolled items matching '{$search}'", $blob)
		);
	}

	/** Clear the current loot list */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootClearCommand(CmdContext $context, #[Str('clear')] string $action): void {
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
	public function lootAddByIdCommand(CmdContext $context, #[Str('add')] string $action, int $id): void {
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
			if (count($this->loot) > 0) {
				$key = count($this->loot) + 1;
			} else {
				$key = 1;
			}

			$this->loot[$key] = $item = new LootItem(
				name: $loot->name,
				icon: $loot->item?->icon,
				added_by: $context->char->name,
				display: $loot->item?->getLink($loot->ql, $loot->name) ?? $loot->name,
				comment: $loot->comment,
				multiloot: $loot->multiloot,
			);
		}

		$msg = "{$context->char->name} added <highlight>{$item->name}<end> (x{$item->multiloot}). To add use <symbol>add {$key}.";
		$this->chatBot->sendPrivate($msg);
	}

	/** Auction off an item from a loot list */
	#[NCA\HandlesCommand(self::CMD_LOOT_MANAGE)]
	#[NCA\Help\Group('loot')]
	public function lootAuctionByIdCommand(CmdContext $context, #[Str('auction')] string $action, int $id): void {
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
		#[Str('raffle')] string $action,
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
	public function lootAddCommand(CmdContext $context, #[Str('add')] string $action, string $item): void {
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
		#[Str('addmulti', 'multiadd')] string $action,
		#[Quantity] int $amount,
		string $items
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$this->addLootItem($items, $amount, $context->char->name);
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

		$row = $this->db->table(AODBEntry::getTable())
			->whereIlike('name', $itemName)
			->firstObj(AODBEntry::class);
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
			if (count($this->loot) > 0) {
				$key = count($this->loot) + 1;
			} else {
				$key = 1;
			}

			$this->loot[$key] = $item = new LootItem(
				name: $itemName,
				icon: $looticon??null,
				added_by: $sender,
				multiloot: $multiloot,
				display: isset($itemHighID)
					? Text::makeItem($itemLowID??$itemHighID, $itemHighID, $itemQL??1, $itemName)
					: $itemName,
			);
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
	public function lootRemCommand(CmdContext $context, #[Remove] string $action, int $key): void {
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
		if (!count($this->residual)) {
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
		if (!count($this->residual)) {
			$msg = 'There are no remaining items to mark ffa.';
			$context->reply($msg);
			return;
		}

		$list = '';
		$numItems = 0;
		foreach ($this->residual as $key => $item) {
			if ($item->icon !== null && $this->showLootPics) {
				$list .= Text::makeImage($item->icon) . "\n";
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
			$msg = Text::makeBlob('All remaining items', $list, 'These items are FFA').
				" were declared <green>free for all<end> by <highlight>{$context->char->name}.";
		} else {
			$msg = Text::makeBlob('The remaining item', $list, 'This item is FFA').
				" was declared <green>free for all<end> by <highlight>{$context->char->name}.";
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
		if (!count($this->loot)) {
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
			if ($numUsers === 1) {
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
				$list .= "<grey>{$this->noOneAddedName}<end>\n\n";
				$this->residual[$resnum] = $item;
				$resnum++;
				$lootHistory->winner = null;
				$this->db->insert($lootHistory);
			} else {
				/** @psalm-var non-empty-array<string, bool> */
				$users = $item->users;
				if ($item->multiloot > 1) {
					$arrolNum = min($item->multiloot, $numUsers);
					// Get $arrolNum random values from $users
					$winners = (array)array_rand($users, $arrolNum);
					foreach ($winners as $winner) {
						$lootHistory->winner = $winner;
						$this->db->insert($lootHistory);
					}
					$item->users = [];
					$list .= implode(
						', ',
						array_map(
							static fn (string $name): string => "<highlight>{$name}<end>",
							$winners
						)
					);

					if ($arrolNum < $item->multiloot) {
						$newmultiloot = $item->multiloot - $arrolNum;
						$this->residual[$resnum] = $item;
						$this->residual[$resnum]->multiloot = $newmultiloot;
						$resnum++;
					}
				} else {
					$winner = array_rand($users, 1);
					$lootHistory->winner = $winner;
					$this->db->insert($lootHistory);
					$list .= "<green>{$winner}<end>";
				}
				$list .= "\n\n";
			}
		}

		// Reset loot
		$this->loot = [];
		$this->roll++;

		// Show winner list
		if (count($this->residual) > 0) {
			$list .= "\n\n".
				Text::makeChatcmd('Reroll remaining items', '/tell <myname> reroll').
				'<tab>'.
				Text::makeChatcmd('Announce remaining items FFA', '/tell <myname> ffa');
		}
		$msg = Text::makeBlob('Winner List', $list);
		if (count($this->residual) > 0) {
			$msg .= ' (There are item(s) left to be rolled. To re-add, type <symbol>reroll, or '.
				'use <symbol>ffa to make them free for all)';
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
			if ($this->loot[$key]->users[$context->char->name] === true) {
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
			$tellMsg = "You changed to <highlight>\"{$this->loot[$slot]->name}\"<end>.";
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
			if ($this->loot[$key]->users[$context->char->name] === true) {
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

	/** Get the current loot list */
	public function getCurrentLootList(): string {
		if (!count($this->loot)) {
			$msg = 'No loot list exists yet.';
			return $msg;
		}

		$flatroll = Text::makeChatcmd('<symbol>flatroll', '/tell <myname> flatroll');
		$list = "Use {$flatroll} to roll.\n\n";
		$players = 0;
		$items = count($this->loot);
		foreach ($this->loot as $key => $item) {
			$add = Text::makeChatcmd('add', "/tell <myname> add {$key}");
			$rem = Text::makeChatcmd('remove', '/tell <myname> rem');
			$addedPlayers = count($item->users);
			$players += $addedPlayers;

			if ($item->icon !== null && $this->showLootPics) {
				$list .= Text::makeImage($item->icon) . "\n";
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
				$list .= "\n<tab>Players added (<highlight>{$addedPlayers}<end>): ";
				$list .= implode(
					', ',
					array_map(
						static fn (string $name): string => "<yellow>{$name}<end>",
						array_keys($item->users)
					)
				);
			}

			$list .= "\n\n";
		}
		$msg = Text::makeBlob("Loot List (Items: {$items}, Players: {$players})", $list);

		return $msg;
	}

	/** Add all items from a raid_loot to the loot list */
	public function addRaidToLootList(string $addedBy, string $raid, string $category): bool {
		// clear current loot list
		$this->loot = [];
		$count = 1;

		$data = $this->db->table(RaidLoot::getTable())
			->where(['raid' => $raid, 'category' => $category])
			->asObj(RaidLoot::class);

		if ($data->count() === 0) {
			return false;
		}

		$itemsByBame =$this->itemsController->getByNames(...$data->pluck('name')->toArray())
			->groupBy('name');
		$data->each(static function (RaidLoot $loot) use ($itemsByBame): void {
			$loot->item = $itemsByBame->get($loot->name)
				?->where('lowql', '<=', $loot->ql)
				?->where('highql', '>=', $loot->ql)
				?->first();
		});

		foreach ($data as $row) {
			$item = $row->name;
			if (isset($row->item)) {
				$item = $row->item->getLink($row->ql);
			}
			$this->loot[$count] = new LootItem(
				comment: $row->comment,
				icon: $row->item->icon ?? null,
				multiloot: $row->multiloot,
				name: $row->name,
				added_by: $addedBy,
				display: ($row->comment === '')
					? $item
					: $item . " ({$row->comment})",
			);

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
	public function mlootCommand(CmdContext $context, #[SpaceOptional] PItem ...$items): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		foreach ($items as $item) {
			$this->addLootItem($item(), 1, $context->char->name, true);
		}
		$lootList = $this->getCurrentLootList();
		$this->chatBot->sendPrivate(
			"{$context->char->name} added " . count($items) . " items to the {$lootList}."
		);
	}

	protected function getLootEntryID(int $id): ?RaidLoot {
		$raidLoot = $this->db->table(RaidLoot::getTable())
			->where('id', $id)
			->firstObj(RaidLoot::class);
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
			$winners = Text::arraySprintf('<green>%s<end>', ...$winners);
			$line .= ': ' . Text::enumerate(...$winners);
		}
		return $line;
	}

	/**
	 * @param Collection<int,LootHistory> $items
	 *
	 * @return Collection<int,LootHistory>
	 */
	private function compressLootHistory(Collection $items): Collection {
		$lastRoll = 0;
		$lastPos = 0;

		/** @var Collection<int,LootHistory> */
		$compressedList = new Collection();
		foreach ($items as $item) {
			if ($lastRoll === $item->roll && $lastPos === $item->pos) {
				if (!isset($item->winner)) {
					continue;
				}

				/** @psalm-suppress PossiblyNullPropertyFetch,PossiblyNullPropertyAssignment */
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
