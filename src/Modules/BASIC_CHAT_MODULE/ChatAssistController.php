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
 *      alias       = 'callers'
 *	)
 *	@DefineCommand(
 *		command     = 'assist .+',
 *		accessLevel = 'rl',
 *		description = 'Sets a new assist',
 *		help        = 'assist.txt'
 *	)
 *	@ProvidesEvent("assist(clear)")
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
	 * Contains the assist macro message.
	 */
	private ?string $assistMessage = null;

	/**
	 * @HandlesCommand("assist")
	 * @Matches("/^assist$/i")
	 */
	public function assistCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->assistMessage)) {
			$msg = "No assist set.";
			$sendto->reply($msg);
			return;
		}
		$sendto->reply($this->assistMessage);
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

		$this->assistMessage = null;
		$sendto->reply("Assist has been cleared.");
		$event = new AssistEvent();
		$event->type = "assist(clear)";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist (.+)$/i")
	 */
	public function assistSetCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$nameArray = preg_split("/\s+|,\s*/", $args[1]);
		$event = new AssistEvent();
		$event->type = "assist(add)";
		
		if (count($nameArray) === 1) {
			$name = ucfirst(strtolower($args[1]));
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$msg = "Character <highlight>$name<end> does not exist.";
				$sendto->reply($msg);
				return;
			} elseif ($channel === "priv" && !isset($this->chatBot->chatlist[$name])) {
				$msg = "Character <highlight>$name<end> is not in this bot.";
				$sendto->reply($msg);
				return;
			}
			$event->players []= $name;

			$link = $this->text->makeChatcmd("Click here to make an assist $name macro", "/macro $name /assist $name");
			$this->assistMessage = $this->text->makeBlob("Assist $name Macro", $link);
			$sendto->reply($this->assistMessage);
			$this->eventManager->fireEvent($event);
			return;
		}

		$errors = [];
		foreach ($nameArray as $key => $name) {
			$name = ucfirst(strtolower($name));
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$errors []= "Character <highlight>$name<end> does not exist.";
			} elseif ($channel === "priv" && !isset($this->chatBot->chatlist[$name])) {
				$errors []= "Character <highlight>$name<end> is not in this bot.";
			} else {
				$event->players []= $name;
			}

			$nameArray[$key] = "/assist $name";
		}
		if (count($errors)) {
			$sendto->reply(join("\n", $errors));
			$this->eventManager->fireEvent($event);
			return;
		}

		// reverse array so that the first character will be the primary assist, and so on
		$nameArray = array_reverse($nameArray);
		$this->assistMessage = '/macro assist ' . implode(" \\n ", $nameArray);

		$sendto->reply($this->assistMessage);
		$this->eventManager->fireEvent($event);
	}
}
