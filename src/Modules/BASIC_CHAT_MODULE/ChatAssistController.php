<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	EventManager,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PRemove,
	ParamClass\PWord,
	SettingManager,
	Text,
	Util,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "assist",
		accessLevel: "guest",
		description: "Shows the assist macro",
		alias: "callers"
	),
	NCA\DefineCommand(
		command: ChatAssistController::CMD_SET_ADD_CLEAR,
		accessLevel: "rl",
		description: "Set, add or clear assists",
	),
	NCA\ProvidesEvent("assist(clear)"),
	NCA\ProvidesEvent("assist(set)"),
	NCA\ProvidesEvent("assist(add)")
]
class ChatAssistController extends ModuleInstance {
	public const CMD_SET_ADD_CLEAR = "assist set/add/clear";

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public ChatLeaderController $chatLeaderController;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	/**
	 * Names of all callers
	 * @var array<string,CallerList>
	 */
	protected array $callers = [];

	/**
	 * Backups of last callers
	 * @var CallerBackup[]
	 */
	protected array $lastCallers = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "callers_undo_steps",
			description: "Max stored undo steps",
			mode: "edit",
			type: "number",
			value: "5"
		);
	}

	/** Store a caller backup */
	protected function storeBackup(CallerBackup $backup): void {
		$this->lastCallers []= $backup;
		$this->lastCallers = array_slice(
			$this->lastCallers,
			-1 * ($this->settingManager->getInt('callers_undo_steps')??5)
		);
	}

	/**
	 * Save the last callers configuration
	 * @return CallerBackup
	 */
	public function backupCallers(string $sender, string $command): CallerBackup {
		$lastCallers = [];
		foreach ($this->callers as $name => $callerList) {
			$lastCallers[$name]= clone($callerList);
		}
		$commands = explode(" ", $command, 2);
		if (strtolower($commands[0]) === 'assist') {
			$commands[0] = "callers";
		}
		return new CallerBackup($sender, join(" ", $commands), $lastCallers);
	}

	/** Return the total amount of callers */
	public function countCallers(): int {
		$count = array_sum(
			array_map(
				function(CallerList $list): int {
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
			function (CallerList $list): bool {
				return $list->count() > 0;
			}
		);
	}

	public function getAssistMessage(): string {
		$blob = "";
		foreach ($this->callers as $name => $callerList) {
			$clearLink = $this->text->makeChatcmd("Clear", "/tell <myname> callers clear {$callerList->name}");
			if (strlen($name)) {
				$blob .= "<header2>{$callerList->name} [{$clearLink}]<end>\n";
			} else {
				$blob .= "<header2>Callers [{$clearLink}]<end>\n";
			}
			for ($i = 0; $i < count($callerList->callers); $i++) {
				$caller = $callerList->callers[$i]->name;
				$blob .= "<tab>" . ($i + 1) . ". <highlight>" . $caller . "<end>".
					" [" . $this->text->makeChatcmd("Macro", "/macro {$caller} /assist {$caller}") . "]".
					" [" . $this->text->makeChatcmd("Assist", "/assist {$caller}") . "]".
					" [" . $this->text->makeChatcmd("Remove", "/tell <myname> callers rem {$name}.{$caller}") . "]".
					"\n";
			}
			$blob .= "\n<tab>Macro: <highlight>/macro ";
			if (strlen($name)) {
				$blob .= $callerList->name;
			} else {
				$blob .= $this->chatBot->char->name;
			}
			$blob .= " /assist " . join(" \\n /assist ", $callerList->getNames());
			$blob .= "<end>\n<tab>Once: ".
				$this->text->makeChatcmd(
					"Assist",
					"/assist " . join(" \\n /assist ", $callerList->getNames())
				);
			$blob .= "\n\n\n";
		}
		return $blob;
	}

	/** Show the current caller(s) and assist macro */
	#[NCA\HandlesCommand("assist")]
	public function assistCommand(CmdContext $context): void {
		if (empty($this->callers)) {
			$msg = "No callers have been set yet.";
			$context->reply($msg);
			return;
		}
		$context->reply($this->text->makeBlob("Current callers", $this->getAssistMessage()));
	}

	/** Remove a player from all or only a specific assist list */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	#[NCA\Help\Example("<symbol>assist rem Nady", "Remove Nady from all assist lists")]
	#[NCA\Help\Example("<symbol>assist rem FOO.Nady", "Remove Nady from the assist lists FOO")]
	public function assistRemCommand(CmdContext $context, PRemove $action, string $toRemove): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$parts = explode(".", $toRemove, 2);
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
			for ($i = 0; $i < count($list->callers); $i++) {
				$caller = $list->callers[$i];
				if ($caller->name === $toRemove) {
					$removed = true;
					unset($list->callers[$i]);
				}
			}
			$list->callers = array_values($list->callers);
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
	#[NCA\Help\Example("<symbol>assist clear FOO", "Clear the assist list FOO")]
	#[NCA\Help\Example(
		"<symbol>assist clear mine",
		"Clear the assist list from all callers added by yourself"
	)]
	#[NCA\Help\Example(
		"<symbol>assist clear notmine",
		"Clear the assist list from all callers not added by yourself"
	)]
	public function assistClearListCommand(
		CmdContext $context,
		#[NCA\Str("clear")] string $action,
		#[NCA\Regexp(".*")] string $assistList
	): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$backup = $this->backupCallers($context->char->name, $context->message);
		$countBefore = $this->countCallers();
		$assistList = strtolower($assistList);
		if ($assistList === '' || isset($this->callers[$assistList])) {
			$name = $this->callers[$assistList]->name;
			unset($this->callers[$assistList]);
			if ($assistList === '') {
				$msg = "Global callers have been cleared";
			} else {
				$msg = "Callers for <highlight>{$name}<end> have been cleared";
			}
		} elseif ($assistList === "mine") {
			foreach ($this->callers as $list) {
				$list->removeCallersAddedBy($context->char->name, false, false);
			}
			$msg = "All your callers have been cleared";
		} elseif ($assistList === "notmine") {
			foreach ($this->callers as $list) {
				$list->removeCallersAddedBy($context->char->name, false, true);
			}
			$msg = "All callers not added by you have been cleared.";
		} else {
			$removed = [];
			foreach ($this->callers as $list) {
				array_push($removed, ...$list->removeCallersAddedBy($assistList, true, false));
			}
			$addedBy = array_unique(array_column($removed, "addedBy"));
			if (!count($addedBy)) {
				$context->reply("No callers found that were added by <highlight>{$assistList}<end>.");
				return;
			}
			$addedBy = $this->text->arraySprintf("<highlight>%s<end>", ...$addedBy);
			$search = $this->text->enumerate(...$addedBy);
			$msg = "All callers added by {$search} have been cleared";
		}
		$countAfter = $this->countCallers();
		$msg .= " (" . ($countBefore - $countAfter) . "/{$countBefore})";
		$context->reply($msg);
		$this->cleanupEmptyLists();
		$this->storeBackup($backup);

		$event = new AssistEvent();
		$event->type = "assist(clear)";
		$this->eventManager->fireEvent($event);
	}

	/** Clear all assist lists */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistClearCommand(CmdContext $context, #[NCA\Str("clear")] string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$this->clearCallers($context->char->name, $context->message);
		$context->reply("Callers have been cleared.");
	}

	/**
	 * Clear the list of callers
	 * @param string $sender The person who clears the callers
	 * @param string $command The command to log in the caller history
	 */
	public function clearCallers(string $sender, string $command): void {
		if (count($this->callers)) {
			$this->storeBackup($this->backupCallers($sender, $command));
		}
		$this->callers = [];
		$event = new AssistEvent();
		$event->type = "assist(clear)";
		$this->eventManager->fireEvent($event);
	}

	/** Create an assist macro for multiple characters */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistSetCommand(CmdContext $context, #[NCA\Str("set")] string $action, PCharacter ...$callers): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		$errors = [];
		$newCallers = [];
		$groupName = "";
		for ($i = 0; $i < count($callers); $i++) {
			$name = $callers[$i]();
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$errors []= "Character <highlight>$name<end> does not exist.";
			} elseif (
				!isset($this->chatBot->guildmembers[$name])
				&& !$this->buddylistManager->isUidOnline($uid)
				&& !isset($this->chatBot->chatlist[$name])
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
			$context->reply(join("\n", $errors));
			return;
		}

		// reverse array so that the first character will be the primary assist, and so on
		$newCallers = array_map(
			function(string $name) use ($context): Caller {
				return new Caller($name, $context->char->name);
			},
			array_reverse($newCallers)
		);
		$backup = $this->backupCallers($context->char->name, $context->message);
		$groupKey = strtolower($groupName);
		$this->callers[$groupKey] = new CallerList();
		$this->callers[$groupKey]->creator = $context->char->name;
		$this->callers[$groupKey]->name = $groupName;
		$this->callers[$groupKey]->callers = $newCallers;
		$this->storeBackup($backup);

		$blob = (array)$this->text->makeBlob("list of callers", $this->getAssistMessage());
		foreach ($blob as &$page) {
			if ($groupName === "") {
				$page = "Callers set, here is the {$page}";
			} else {
				$page = "Callers set for <highlight>$groupName<end>, here is the {$page}";
			}
		}
		$context->reply($blob);
		$event = new AssistEvent();
		$event->type = "assist(set)";
		$event->lists = array_values($this->callers);
		$this->eventManager->fireEvent($event);
	}

	/** Add a new player to the global assist list, or the one given */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistAddCommand(CmdContext $context, #[NCA\Str("add")] string $action, ?PWord $assistList, PCharacter $caller): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}

		$assistList = isset($assistList) ? $assistList() : "";
		$name = $caller();
		$groupKey = strtolower($assistList);
		$event = new AssistEvent();
		$event->type = "assist(add)";

		$name = ucfirst(strtolower($name));
		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$context->reply("Character <highlight>$name<end> does not exist.");
			return;
		} elseif (
			!isset($this->chatBot->guildmembers[$name])
			&& !$this->buddylistManager->isUidOnline($uid)
			&& !isset($this->chatBot->chatlist[$name])
		) {
			$context->reply("Character <highlight>$name<end> is not in this bot.");
			return;
		}
		$backup = $this->backupCallers($context->char->name, $context->message);
		if (!isset($this->callers[$groupKey])) {
			$this->callers[$groupKey] = new CallerList();
			$this->callers[$groupKey]->creator = $context->char->name;
			$this->callers[$groupKey]->name = $assistList;
			$this->callers[$groupKey]->callers = [new Caller($name, $context->char->name)];
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
		$msg = $this->text->blobWrap(
			"{$msg}. ",
			$this->text->makeBlob("List of callers", $blob)
		);
		$context->reply($msg);
		$event->lists = array_values($this->callers);
		$this->eventManager->fireEvent($event);
	}

	/** Undo the last &lt;steps&gt; or 1 modification(s) of the caller list */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistUndoCommand(CmdContext $context, #[NCA\Str("undo")] string $action, ?int $steps): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		if (empty($this->lastCallers)) {
			$context->reply("No last caller configuration found.");
			return;
		}
		$steps ??= 1;
		$this->callers = array_splice($this->lastCallers, -1 * $steps)[0]->callers;
		$msg = "Callers configuration restored. ";
		if (count($this->callers) > 0) {
			$msg = $this->text->blobWrap(
				$msg,
				$this->text->makeBlob("List of callers", $this->getAssistMessage())
			);
		} else {
			$msg .= "No callers set.";
		}
		$context->reply($msg);
		$event = new AssistEvent();
		$event->type = "assist(set)";
		$event->lists = array_values($this->callers);
		$this->eventManager->fireEvent($event);
	}

	/** See the most recent changes to the list of callers */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistHistoryCommand(CmdContext $context, #[NCA\Str("history")] string $action): void {
		if (!$this->chatLeaderController->checkLeaderAccess($context->char->name)) {
			$context->reply("You must be Raid Leader to use this command.");
			return;
		}
		if (empty($this->lastCallers)) {
			$context->reply("No last caller configuration found.");
			return;
		}
		$undo = $count = count($this->lastCallers);
		$blob = "<header2>Last changes to callers<end>\n";
		foreach ($this->lastCallers as $backup) {
			$undoLink = $this->text->makeChatcmd(
				"Undo to here",
				"/tell <myname> callers undo {$undo}"
			);
			$undo--;
			$blob .= "<tab>[{$undoLink}]\n".
				"<tab>".
				$this->util->date($backup->time->getTimestamp()).
				"<tab><highlight><symbol>{$backup->command}<end> ({$backup->changer})\n";
		}
		$msg = $this->text->makeBlob("Caller history ({$count})", $blob);
		$context->reply($msg);
	}

	/** Create an assist macro for a single character */
	#[NCA\HandlesCommand(ChatAssistController::CMD_SET_ADD_CLEAR)]
	public function assistOnceCommand(CmdContext $context, PCharacter $char): void {
		$name = $char();
		if (!$this->chatBot->get_uid($name)) {
			$context->reply("No player named <highlight>{$name}<end> found.");
			return;
		}
		$blob = "<header2>Assist macro<end>\n".
			"<tab>" . $this->text->makeChatcmd("Click me for a macro", "/macro {$name} /assist {$name}");
		$context->reply(
			$this->text->blobWrap(
				"Please all ",
				$this->text->makeBlob("assist {$name}", $blob, "Quick assist macro for {$name}")
			)
		);
	}
}
