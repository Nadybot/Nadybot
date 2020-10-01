<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\{
	CommandReply,
	EventManager,
	Nadybot,
	Text,
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
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public EventManager $eventManager;
	
	/**
	 * Names of all callers
	 * @var array<string,CallerList>
	 */
	protected array $callers = [];

	public function getAssistMessage(): string {
		$blob = "";
		foreach ($this->callers as $name => $callerList) {
			if (strlen($name)) {
				$blob .= "<header2>{$callerList->name}<end>\n";
			} else {
				$blob .= "<header2>Callers<end>\n";
			}
			foreach ($callerList->callers as $player) {
				$blob .= "<tab>$player\n";
			}
			$blob .= "\n<highlight>Macro<end>: /macro ";
			if (strlen($name)) {
				$blob .= $this->chatBot->vars["name"];
			} else {
				$blob .= $callerList->name;
			}
			$blob . " assist " . join("\\n assist ", $callerList->callers);
			$blob .= "\n\n";
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
		$sendto->reply($this->text->makeBlob("Callers", $this->getAssistMessage()));
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
		$callers = array_reverse($callers);
		$groupKey = strtolower($groupName);
		$this->callers[$groupKey] = new CallerList();
		$this->callers[$groupKey]->name = $groupName;
		$this->callers[$groupKey]->callers = $callers;

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
		if (!isset($this->callers[$groupKey])) {
			$this->callers[$groupKey] = new CallerList();
			$this->callers[$groupKey]->name = $groupName;
			$this->callers[$groupKey]->callers = [$name];
			$msg = "Added <highlight>{$name}<end> to the list of callers \"<highlight>{$groupName}<end>\"";
		} else {
			array_unshift($this->callers[$groupKey]->callers, $name);
			$msg = "Added <highlight>{$name}<end> to the new list of callers \"<highlight>{$groupName}<end>\"";
		}

		$blob = $this->getAssistMessage();
		$msg .= ". " . $this->text->makeBlob("List of callers", $blob);
		$sendto->reply($msg);
		$event->lists = array_values($this->callers);
		$this->eventManager->fireEvent($event);
	}
}
