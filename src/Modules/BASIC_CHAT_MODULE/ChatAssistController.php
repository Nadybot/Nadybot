<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Exception;
use Nadybot\Core\Attributes\Parameter\{Regexp,Remove,Str,WordStr};
use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	Config\BotConfig,
	DBSchema\Player,
	EventManager,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\PlayerManager,
	MyOrg,
	Nadybot,
	ParamClass\PCharacter,
	Text,
	Types\AccessLevel,
	Types\Profession,
	Util,
};
use Nadybot\Modules\RAID_MODULE\RaidController;
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'assist',
		accessLevel: AccessLevel::Guest,
		description: 'Shows the assist macro',
		alias: 'callers'
	),
	NCA\DefineCommand(
		command: ChatAssistController::CMD_SET_ADD_CLEAR,
		accessLevel: AccessLevel::RaidLeader,
		description: 'Set, add or clear assists',
	),
]
class ChatAssistController extends ModuleInstance {
	public const CMD_SET_ADD_CLEAR = 'assist set/add/clear';

	/** Max stored undo steps */
	#[NCA\Setting\Number]
	public int $callersUndoSteps = 5;

	/** List of classes never to pick as random callers */
	#[NCA\Setting\Text(options: [
		'doc',
		'crat',
		'enforcer',
		'doc:crat',
		'doc:crat:enforcer',
	])]
	public string $neverAutoCallers = 'doc:crat:enforcer';

	/**
	 * Names of all callers
	 *
	 * @var array<string,CallerList>
	 */
	protected array $callers = [];

	/**
	 * Backups of last callers
	 *
	 * @var CallerBackup[]
	 */
	protected array $lastCallers = [];

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private ChatLeaderController $chatLeaderController;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private RaidController $raidController;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private MyOrg $myOrg;

	#[NCA\SettingChangeHandler('never_auto_callers')]
	public function validateNeverAutoCallers(string $setting, string $old, string $new): void {
		$profs = explode(':', $new);
		foreach ($profs as $prof) {
			try {
				Profession::fromName($prof);
			} catch (Throwable $e) {
				throw new Exception("<highlight>{$prof}<end> is not a recognized profession", 0, $e);
			}
		}
	}

	/** Save the last callers configuration */
	public function backupCallers(string $sender, string $command): CallerBackup {
		$lastCallers = [];
		foreach ($this->callers as $name => $callerList) {
			$lastCallers[$name]= clone $callerList;
		}
		$commands = explode(' ', $command, 2);
		if (strtolower($commands[0]) === 'assist') {
			$commands[0] = 'callers';
		}
		return new CallerBackup(
			changer: $sender,
			command: implode(' ', $commands),
			callers: $lastCallers
		);
	}

	/** Return the total amount of callers */
	public function countCallers(): int {
		$count = array_sum(
			array_map(
				static function (CallerList $list): int {
					return $list->count();
				},
				$this->callers
			)
		);
		return $count;
	}

	/** Remove empty caller lists */
	public function cleanupEmptyLists(): void {
		// Remove all empty caller lists
		$this->callers = array_filter(
			$this->callers,
			static function (CallerList $list): bool {
				return $list->count() > 0;
			}
		);
	}

	public function getAssistMessage(): string {
		$blob = '';
		foreach ($this->callers as $name => $callerList) {
			$clearLink = Text::makeChatcmd('Clear', "/tell <myname> callers clear {$callerList->name}");
			if (strlen($name)) {
				$blob .= "<header2>{$callerList->name} [{$clearLink}]<end>\n";
			} else {
				$blob .= "<header2>Callers [{$clearLink}]<end>\n";
			}
			for ($i = 0; $i < count($callerList->callers); $i++) {
				$caller = $callerList->callers[$i]->name;
				$blob .= '<tab>' . ($i + 1) . '. <highlight>' . $caller . '<end>'.
					' [' . Text::makeChatcmd('Macro', "/macro {$caller} /assist {$caller}") . ']'.
					' [' . Text::makeChatcmd('Assist', "/assist {$caller}") . ']'.
					' [' . Text::makeChatcmd('Remove', "/tell <myname> callers rem {$name}.{$caller}") . ']'.
					"\n";
			}
			$blob .= "\n<tab>Macro: <highlight>/macro ";
			if (strlen($name)) {
				$blob .= $callerList->name;
			} else {
				$blob .= $this->config->main->character;
			}
			$blob .= ' /assist ' . implode(' \\n /assist ', $callerList->getNames());
			$blob .= "<end>\n<tab>Once: ".
				Text::makeChatcmd(
					'Assist',
					'/assist ' . implode(' \\n /assist ', $callerList->getNames())
				);
			$blob .= "\n\n\n";
		}
		return $blob;
	}

	/** Show the current caller(s) and assist macro */
	#[NCA\HandlesCommand('assist')]
	public function assistCommand(CmdContext $context): void {
		if (!count($this->callers)) {
			$msg = 'No callers have been set yet.';
			$context->reply($msg);
			return;
		}
		$context->reply(Text::makeBlob('Current callers', $this->getAssistMessage()));
	}

	/** Remove a player from all or only a specific assist list */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	#[NCA\Help\Example('<symbol>assist rem Nady', 'Remove Nady from all assist lists')]
	#[NCA\Help\Example('<symbol>assist rem FOO.Nady', 'Remove Nady from the assist lists FOO')]
	public function assistRemCommand(
		CmdContext $context,
		#[Remove] string $action,
		string $toRemove
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		$parts = explode('.', $toRemove, 2);
		$group = null;
		if (count($parts) === 2) {
			$toRemove = ucfirst(strtolower($parts[1]));
			$group = strtolower($parts[0]);
		} else {
			$toRemove = ucfirst(strtolower($toRemove));
		}
		$removed = false;
		$backup = $this->backupCallers($context->char->name, $context->message);
		foreach ($this->callers as $name => $list) {
			if (isset($group) && $group !== $name) {
				continue;
			}
			$list->callers = array_values(
				array_filter(
					$list->callers,
					static function (Caller $caller) use (&$removed, $toRemove): bool {
						if ($caller->name === $toRemove) {
							$removed = true;
							return false;
						}
						return true;
					}
				)
			);
		}
		if (!$removed) {
			$msg = "<highlight>{$toRemove}<end> is not in the list of callers.";
			$context->reply($msg);
			return;
		}
		$this->storeBackup($backup);
		$this->cleanupEmptyLists();
		$msg = "Removed <highlight>{$toRemove}<end> from the list of callers.";
		$context->reply($msg);
	}

	/** Clear a specific assist list */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	#[NCA\Help\Example('<symbol>assist clear FOO', 'Clear the assist list FOO')]
	#[NCA\Help\Example(
		'<symbol>assist clear mine',
		'Clear the assist list from all callers added by yourself'
	)]
	#[NCA\Help\Example(
		'<symbol>assist clear notmine',
		'Clear the assist list from all callers not added by yourself'
	)]
	public function assistClearListCommand(
		CmdContext $context,
		#[Str('clear')] string $action,
		#[Regexp('.*')] string $assistList
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$backup = $this->backupCallers($context->char->name, $context->message);
		$countBefore = $this->countCallers();
		$assistList = strtolower($assistList);
		if ($assistList === '' || isset($this->callers[$assistList])) {
			$name = $this->callers[$assistList]->name;
			unset($this->callers[$assistList]);
			if ($assistList === '') {
				$msg = 'Global callers have been cleared';
			} else {
				$msg = "Callers for <highlight>{$name}<end> have been cleared";
			}
		} elseif ($assistList === 'mine') {
			foreach ($this->callers as $list) {
				$list->removeCallersAddedBy($context->char->name, false, false);
			}
			$msg = 'All your callers have been cleared';
		} elseif ($assistList === 'notmine') {
			foreach ($this->callers as $list) {
				$list->removeCallersAddedBy($context->char->name, false, true);
			}
			$msg = 'All callers not added by you have been cleared.';
		} else {
			$removed = [];
			foreach ($this->callers as $list) {
				array_push($removed, ...$list->removeCallersAddedBy($assistList, true, false));
			}
			$addedBy = array_unique(array_column($removed, 'addedBy'));
			if (!count($addedBy)) {
				$context->reply("No callers found that were added by <highlight>{$assistList}<end>.");
				return;
			}
			$addedBy = Text::arraySprintf('<highlight>%s<end>', ...$addedBy);
			$search = Text::enumerate(...$addedBy);
			$msg = "All callers added by {$search} have been cleared";
		}
		$countAfter = $this->countCallers();
		$msg .= ' (' . ($countBefore - $countAfter) . "/{$countBefore})";
		$context->reply($msg);
		$this->cleanupEmptyLists();
		$this->storeBackup($backup);

		$event = new AssistClearEvent();
		$this->eventManager->dispatch($event);
	}

	/** Clear all assist lists */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistClearCommand(CmdContext $context, #[Str('clear')] string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		$this->clearCallers($context->char->name, $context->message);
		$context->reply('Callers have been cleared.');
	}

	/**
	 * Clear the list of callers
	 *
	 * @param string $sender  The person who clears the callers
	 * @param string $command The command to log in the caller history
	 */
	public function clearCallers(string $sender, string $command): void {
		if (count($this->callers)) {
			$this->storeBackup($this->backupCallers($sender, $command));
		}
		$this->callers = [];
		$event = new AssistClearEvent();
		$this->eventManager->dispatch($event);
	}

	/** Create an assist macro for multiple characters */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistSetCommand(
		CmdContext $context,
		#[Str('set')] string $action,
		PCharacter ...$callers
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		$errors = [];
		$newCallers = [];
		$groupName = '';
		for ($i = 0; $i < count($callers); $i++) {
			$name = $callers[$i]();
			$uid = $this->chatBot->getUid($name);
			if (!isset($uid)) {
				$errors []= "Character <highlight>{$name}<end> does not exist.";
			} elseif (
				!$this->myOrg->isMember($name)
				&& !$this->buddylistManager->isUidOnline($uid)
				&& !$this->chatBot->inChatlist($name)
			) {
				$errors []= "Character <highlight>{$name}<end> is not in this bot.";
			} else {
				$newCallers []= $name;
			}
			if (count($errors) && $i === 0) {
				$groupName = $callers[$i]();
				$errors = [];
			}
		}
		if (count($errors)) {
			$context->reply(implode("\n", $errors));
			return;
		}

		// reverse array so that the first character will be the primary assist, and so on
		$newCallers = array_map(
			static function (string $name) use ($context): Caller {
				return new Caller($name, $context->char->name);
			},
			array_reverse($newCallers)
		);
		$backup = $this->backupCallers($context->char->name, $context->message);
		$groupKey = strtolower($groupName);
		$this->callers[$groupKey] = new CallerList(
			creator: $context->char->name,
			name: $groupName,
			callers: $newCallers,
		);
		$this->storeBackup($backup);

		$blob = Text::makeBlob('list of callers', $this->getAssistMessage());
		if ($groupName === '') {
			$blob = "Callers set, here is the {$blob}";
		} else {
			$blob = "Callers set for <highlight>{$groupName}<end>, here is the {$blob}";
		}

		$context->reply($blob);
		$event = new AssistSetEvent(
			lists: array_values($this->callers),
		);
		$this->eventManager->dispatch($event);
	}

	/** Add a new player to the global assist list, or the one given */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistAddCommand(
		CmdContext $context,
		#[Str('add')] string $action,
		#[WordStr] ?string $assistList,
		PCharacter $caller
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}

		$assistList ??= '';
		$name = $caller();
		$groupKey = strtolower($assistList);

		$name = ucfirst(strtolower($name));
		$uid = $this->chatBot->getUid($name);
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$name}<end> does not exist.");
			return;
		} elseif (
			!$this->myOrg->isMember($name)
			&& !$this->buddylistManager->isUidOnline($uid)
			&& !$this->chatBot->inChatlist($name)
		) {
			$context->reply("Character <highlight>{$name}<end> is not in this bot.");
			return;
		}
		$backup = $this->backupCallers($context->char->name, $context->message);
		if (!isset($this->callers[$groupKey])) {
			$this->callers[$groupKey] = new CallerList(
				creator: $context->char->name,
				name: $assistList,
				callers: [new Caller($name, $context->char->name)],
			);
			$msg = "Added <highlight>{$name}<end> to the new list of callers";
			if (strlen($assistList)) {
				$msg .= " \"<highlight>{$assistList}<end>\"";
			}
		} else {
			if ($this->callers[$groupKey]->isInList($name)) {
				$context->reply("<highlight>{$name}<end> is already in the list of callers.");
				return;
			}
			array_unshift($this->callers[$groupKey]->callers, new Caller($name, $context->char->name));
			$msg = "Added <highlight>{$name}<end> to the list of callers";
			if (strlen($assistList)) {
				$msg .= " \"<highlight>{$assistList}<end>\"";
			}
		}
		$this->storeBackup($backup);

		$blob = $this->getAssistMessage();
		$msg .= '. ' . Text::makeBlob('List of callers', $blob);
		$context->reply($msg);
		$event = new AssistAddEvent(
			lists: array_values($this->callers),
		);
		$this->eventManager->dispatch($event);
	}

	/** Undo the last &lt;steps&gt; or 1 modification(s) of the caller list */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistUndoCommand(
		CmdContext $context,
		#[Str('undo')] string $action,
		?int $steps
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		if (!count($this->lastCallers)) {
			$context->reply('No last caller configuration found.');
			return;
		}
		$steps ??= 1;
		$this->callers = array_splice($this->lastCallers, -1 * $steps)[0]->callers;
		$msg = 'Callers configuration restored. ';
		if (count($this->callers) > 0) {
			$msg .= Text::makeBlob('List of callers', $this->getAssistMessage());
		} else {
			$msg .= 'No callers set.';
		}
		$context->reply($msg);
		$event = new AssistSetEvent(
			lists: array_values($this->callers),
		);
		$this->eventManager->dispatch($event);
	}

	/** See the most recent changes to the list of callers */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistHistoryCommand(CmdContext $context, #[Str('history')] string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		if (!count($this->lastCallers)) {
			$context->reply('No last caller configuration found.');
			return;
		}
		$undo = $count = count($this->lastCallers);
		$blob = "<header2>Last changes to callers<end>\n";
		foreach ($this->lastCallers as $backup) {
			$undoLink = Text::makeChatcmd(
				'Undo to here',
				"/tell <myname> callers undo {$undo}"
			);
			$undo--;
			$blob .= "<tab>[{$undoLink}]\n".
				'<tab>'.
				Util::date($backup->time->getTimestamp()).
				"<tab><highlight><symbol>{$backup->command}<end> ({$backup->changer})\n";
		}
		$msg = Text::makeBlob("Caller history ({$count})", $blob);
		$context->reply($msg);
	}

	/** Create an assist macro for a single character */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistOnceCommand(CmdContext $context, PCharacter $char): void {
		$name = $char();
		$uid = $this->chatBot->getUid($name);
		if (!isset($uid)) {
			$context->reply("No player named <highlight>{$name}<end> found.");
			return;
		}
		$blob = "<header2>Assist macro<end>\n".
			'<tab>' . Text::makeChatcmd('Click me for a macro', "/macro {$name} /assist {$name}");
		$context->reply(
			'Please all '.
			Text::makeBlob("assist {$name}", $blob, "Quick assist macro for {$name}")
		);
	}

	/** Set a given number of random raid members to be callers */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistRandomCommand(
		CmdContext $context,
		#[Str('random')] string $action,
		int $numCallers
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply('You must be Raid Leader to use this command.');
			return;
		}
		if (!isset($this->raidController->raid)) {
			$context->reply('Picking random callers only works in raids.');
			return;
		}
		if ($numCallers < 1) {
			$context->reply('You have to pick at least 1 caller.');
			return;
		}
		$potentialCallers = [];
		foreach ($this->raidController->raid->raiders as $name => $raider) {
			if (!isset($raider->left)) {
				$potentialCallers []= $raider->player;
			}
		}
		if (!count($potentialCallers)) {
			$context->reply('There is no one in the raid right now.');
			return;
		}
		$potentialCallers = $this->removeNeverCallers(...$potentialCallers);
		if (!count($potentialCallers)) {
			$context->reply('There are no potential callers in the raid.');
			return;
		}
		$pickedCallerIDs = (array)array_rand(
			$potentialCallers,
			min($numCallers, count($potentialCallers))
		);
		$pickedCallers = [];
		$callerNames = [];
		foreach ($pickedCallerIDs as $callerID) {
			$callerNames []= $potentialCallers[$callerID];
			$pickedCallers []= new Caller($potentialCallers[$callerID], $context->char->name);
		}

		$backup = $this->backupCallers($context->char->name, $context->message);
		$this->callers = [];
		$this->callers[''] = new CallerList(
			creator: $context->char->name,
			name: '',
			callers: $pickedCallers,
		);
		$callers = Text::arraySprintf('<highlight>%s<end>', ...$callerNames);
		$msg = 'Set ' . Text::enumerate(...$callers) . ' to be the new callers';
		$this->storeBackup($backup);

		$blob = $this->getAssistMessage();
		$msg .= '. ' . Text::makeBlob('List of callers', $blob);
		$context->reply($msg);
		$event = new AssistSetEvent(
			lists: array_values($this->callers),
		);
		$this->eventManager->dispatch($event);
	}

	/** Store a caller backup */
	protected function storeBackup(CallerBackup $backup): void {
		$this->lastCallers []= $backup;
		$this->lastCallers = array_slice(
			$this->lastCallers,
			-1 * $this->callersUndoSteps
		);
	}

	/**
	 * From a list of character names, return only those whose profession
	 * is not in the never_auto_callers profession list
	 *
	 * @return list<string>
	 */
	protected function removeNeverCallers(string ...$members): array {
		$forbiddenProfs = array_map(
			Profession::fromName(...),
			explode(':', $this->neverAutoCallers)
		);
		$players = $this->playerManager->searchByNames(
			$this->config->main->dimension,
			...$members
		);
		return $players->filter(static function (Player $member) use ($forbiddenProfs): bool {
			return !isset($member->profession)
				|| !in_array($member->profession, $forbiddenProfs, true);
		})->pluck('name')
		->values()
		->toList();
	}
}
