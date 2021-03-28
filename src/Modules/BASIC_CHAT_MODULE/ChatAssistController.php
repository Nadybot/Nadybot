<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	CommandReply,
	EventManager,
	Nadybot,
	SettingManager,
	Text,
	Util,
};

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'assist',
 *		accessLevel = 'all',
 *		description = 'Shows the assist macro',
 *		help        = 'assist.txt',
 *		alias       = 'callers'
 *	)
 *	@DefineCommand(
 *		command     = 'assist .+',
 *		accessLevel = 'rl',
 *		description = 'Set, add or clear assists',
 *		help        = 'assist.txt'
 *	)
 *
 *	@ProvidesEvent("assist(clear)")
 *	@ProvidesEvent("assist(set)")
 *	@ProvidesEvent("assist(add)")
 */
class ChatAssistController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;

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

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"callers_undo_steps",
			"Max stored undo steps",
			"edit",
			"number",
			"5"
		);
	}

	/** Store a caller backup */
	protected function storeBackup(CallerBackup $backup): void {
		$this->lastCallers []= $backup;
		$this->lastCallers = array_slice(
			$this->lastCallers,
			-1 * $this->settingManager->getInt('callers_undo_steps')
		);
	}

	/**
	 * Save the last callers configuration
	 * @return array<string,CallerList>
	 */
	public function backupCallers(string $sender, string $command): CallerBackup {
		$lastCallers = [];
		foreach ($this->callers as $name => $callerList) {
			$lastCallers[$name]= clone($callerList);
		}
		$commands = explode(" ", $command, 2);
		$commands[0] = "callers";
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
				$blob .= $this->chatBot->vars["name"];
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

	/**
	 * @HandlesCommand("assist")
	 * @Matches("/^assist$/i")
	 */
	public function assistCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (empty($this->callers)) {
			$msg = "No callers have been set yet.";
			$sendto->reply($msg);
			return;
		}
		$sendto->reply($this->text->makeBlob("Current callers", $this->getAssistMessage()));
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist rem (.+)$/i")
	 */
	public function assistRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}
		$toRemove = $args[1];
		$parts = explode(".", $args[1], 2);
		$group = null;
		if (count($parts) === 2) {
			$toRemove = ucfirst(strtolower($parts[1]));
			$group = strtolower($parts[0]);
		} else {
			$toRemove = ucfirst(strtolower($toRemove));
		}
		$removed = false;
		$backup = $this->backupCallers($sender, $args[0]);
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
			$sendto->reply($msg);
			return;
		}
		$this->storeBackup($backup);
		$this->cleanupEmptyLists();
		$msg = "Removed <highlight>{$toRemove}<end> from the list of callers.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist clear (.*)$/i")
	 */
	public function assistClearListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$backup = $this->backupCallers($sender, $args[0]);
		$countBefore = $this->countCallers();
		$groupKey = strtolower($args[1]);
		if ($args[1] === '' || isset($this->callers[$groupKey])) {
			$name = $this->callers[$groupKey]->name;
			unset($this->callers[$groupKey]);
			if ($args[1] === '') {
				$msg = "Global callers have been cleared";
			} else {
				$msg = "Callers for <highlight>{$name}<end> have been cleared";
			}
		} elseif ($args[1] === "mine") {
			foreach ($this->callers as $list) {
				$list->removeCallersAddedBy($sender, false, false);
			}
			$msg = "All your callers have been cleared";
		} elseif ($args[1] === "notmine") {
			foreach ($this->callers as $list) {
				$list->removeCallersAddedBy($sender, false, true);
			}
			$msg = "All callers not added by you have been cleared.";
		} else {
			$removed = [];
			foreach ($this->callers as $list) {
				array_push($removed, ...$list->removeCallersAddedBy($args[1], true, false));
			}
			$addedBy = array_unique(array_column($removed, "addedBy"));
			if (!count($addedBy)) {
				$sendto->reply("No callers found that were added by <highlight>{$args[1]}<end>.");
				return;
			}
			$addedBy = $this->text->arraySprintf("<highlight>%s<end>", ...$addedBy);
			$search = $this->text->enumerate(...$addedBy);
			$msg = "All callers added by {$search} have been cleared";
		}
		$countAfter = $this->countCallers();
		$msg .= " (" . ($countBefore - $countAfter) . "/{$countBefore})";
		$sendto->reply($msg);
		$this->cleanupEmptyLists();
		$this->storeBackup($backup);

		$event = new AssistEvent();
		$event->type = "assist(clear)";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist clear$/i")
	 */
	public function assistClearCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		if (count($this->callers)) {
			$this->storeBackup($this->backupCallers($sender, $args[0]));
		}
		$this->callers = [];
		$sendto->reply("Callers have been cleared.");
		$event = new AssistEvent();
		$event->type = "assist(clear)";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist set (.+)$/i")
	 */
	public function assistSetCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$nameArray = preg_split("/\s+|\s*,\s*/", $args[1]);

		$errors = [];
		$callers = [];
		$groupName = "";
		for ($i = 0; $i < count($nameArray); $i++) {
			$name = ucfirst(strtolower($nameArray[$i]));
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$errors []= "Character <highlight>$name<end> does not exist.";
			} elseif ($channel === "priv" && !isset($this->chatBot->chatlist[$name])) {
				$errors []= "Character <highlight>$name<end> is not in this bot.";
			} else {
				$callers []= $name;
			}
			if (count($errors) && $i === 0) {
				$groupName = $nameArray[$i];
				$errors = [];
			}
		}
		if (count($errors)) {
			$sendto->reply(join("\n", $errors));
			return;
		}

		// reverse array so that the first character will be the primary assist, and so on
		$callers = array_map(
			function(string $name) use ($sender): Caller {
				return new Caller($name, $sender);
			},
			array_reverse($callers)
		);
		$backup = $this->backupCallers($sender, $args[0]);
		$groupKey = strtolower($groupName);
		$this->callers[$groupKey] = new CallerList();
		$this->callers[$groupKey]->creator = $sender;
		$this->callers[$groupKey]->name = $groupName;
		$this->callers[$groupKey]->callers = $callers;
		$this->storeBackup($backup);

		if ($groupName === "") {
			$msg = "Callers set, here is the ".
				$this->text->makeBlob("list of callers", $this->getAssistMessage());
		} else {
			$msg = "Callers set for <highlight>$groupName<end>, here is the ".
				$this->text->makeBlob("list of callers", $this->getAssistMessage());
		}
		$sendto->reply($msg);
		$event = new AssistEvent();
		$event->type = "assist(set)";
		$event->lists = array_values($this->callers);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist add ([^ ]+) (.+)$/i")
	 * @Matches("/^assist add (.+)$/i")
	 */
	public function assistAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$groupName = "";
		$name = $args[1];
		if (count($args) === 3) {
			$groupName = $args[1];
			$name = $args[2];
		}
		$groupKey = strtolower($groupName);
		$event = new AssistEvent();
		$event->type = "assist(add)";

		$name = ucfirst(strtolower($name));
		$uid = $this->chatBot->get_uid($name);
		if (!$uid) {
			$sendto->reply("Character <highlight>$name<end> does not exist.");
			return;
		} elseif ($channel === "priv" && !isset($this->chatBot->chatlist[$name])) {
			$sendto->reply("Character <highlight>$name<end> is not in this bot.");
			return;
		}
		$backup = $this->backupCallers($sender, $args[0]);
		if (!isset($this->callers[$groupKey])) {
			$this->callers[$groupKey] = new CallerList();
			$this->callers[$groupKey]->creator = $sender;
			$this->callers[$groupKey]->name = $groupName;
			$this->callers[$groupKey]->callers = [new Caller($name, $sender)];
			$msg = "Added <highlight>{$name}<end> to the new list of callers";
			if (strlen($groupName)) {
				$msg .= " \"<highlight>{$groupName}<end>\"";
			}
		} else {
			if ($this->callers[$groupKey]->isInList($name)) {
				$sendto->reply("<highlight>{$name}<end> is already in the list of callers.");
				return;
			}
			array_unshift($this->callers[$groupKey]->callers, new Caller($name, $sender));
			$msg = "Added <highlight>{$name}<end> to the list of callers";
			if (strlen($groupName)) {
				$msg .= " \"<highlight>{$groupName}<end>\"";
			}
		}
		$this->storeBackup($backup);

		$blob = $this->getAssistMessage();
		$msg .= ". " . $this->text->makeBlob("List of callers", $blob);
		$sendto->reply($msg);
		$event->lists = array_values($this->callers);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist undo$/i")
	 * @Matches("/^assist undo (\d+)$/i")
	 */
	public function assistUndoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}
		if (empty($this->lastCallers)) {
			$sendto->reply("No last caller configuration found.");
			return;
		}
		$undo = (int)($args[1] ?? 1);
		$this->callers = array_splice($this->lastCallers, -1 * $undo)[0]->callers;
		$msg = "Callers configuration restored. ";
		if (count($this->callers) > 0) {
			$msg .= $this->text->makeBlob("List of callers", $this->getAssistMessage());
		} else {
			$msg .= "No callers set.";
		}
		$sendto->reply($msg);
		$event = new AssistEvent();
		$event->type = "assist(set)";
		$event->lists = array_values($this->callers);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist history$/i")
	 */
	public function assistHistoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}
		if (empty($this->lastCallers)) {
			$sendto->reply("No last caller configuration found.");
			return;
		}
		$undo = $count = count($this->lastCallers);
		$blob = "<header2>Last changes to callers<end>\n";
		foreach ($this->lastCallers as $backup) {
			$undoLink = $this->text->makeChatcmd(
				"Undo before this",
				"/tell <myname> callers undo {$undo}"
			);
			$undo--;
			$blob .= "<tab>".
				$this->util->date($backup->time->getTimestamp()).
				"<tab><highlight><symbol>{$backup->command}<end> ({$backup->changer})".
				" [{$undoLink}]\n";
		}
		$msg = $this->text->makeBlob("Caller history ({$count})", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist ([^ ]{4,12})$/i")
	 */
	public function assistOnceCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		if (!$this->chatBot->get_uid($name)) {
			$sendto->reply("No player named <highlight>{$name}<end> found.");
			return;
		}
		$blob = "<header2>Assist macro<end>\n".
			"<tab>" . $this->text->makeChatcmd("Click me for a macro", "/macro {$name} /assist {$name}");
		$sendto->reply("Please all " . $this->text->makeBlob("assist {$name}", $blob, "Quick assist macro for {$name}"));
	}
}
