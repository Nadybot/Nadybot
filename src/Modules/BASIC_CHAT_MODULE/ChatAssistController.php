<?php

namespace Budabot\Modules\BASIC_CHAT_MODULE;

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
 */
class ChatAssistController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;
	
	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
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
	 * Contains the assist macro message.
	 */
	private $assistMessage;

	/**
	 * @HandlesCommand("assist")
	 * @Matches("/^assist$/i")
	 */
	public function assistCommand($message, $channel, $sender, $sendto, $args) {
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
	public function assistClearCommand($message, $channel, $sender, $sendto, $args) {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->assistMessage = null;
		$sendto->reply("Assist has been cleared.");
	}

	/**
	 * @HandlesCommand("assist .+")
	 * @Matches("/^assist (.+)$/i")
	 */
	public function assistSetCommand($message, $channel, $sender, $sendto, $args) {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$nameArray = preg_split("/\s+|,\s*/", $args[1]);
		
		if (count($nameArray) == 1) {
			$name = ucfirst(strtolower($args[1]));
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$msg = "Character <highlight>$name<end> does not exist.";
				$sendto->reply($msg);
				return;
			} elseif ($channel == "priv" && !isset($this->chatBot->chatlist[$name])) {
				$msg = "Character <highlight>$name<end> is not in this bot.";
				$sendto->reply($msg);
				return;
			}

			$link = $this->text->makeChatcmd("Click here to make an assist $name macro", "/macro $name /assist $name");
			$this->assistMessage = $this->text->makeBlob("Assist $name Macro", $link);
			$sendto->reply($this->assistMessage);
			return;
		}
		$errors = array();
		foreach ($nameArray as $key => $name) {
			$name = ucfirst(strtolower($name));
			$uid = $this->chatBot->get_uid($name);
			if (!$uid) {
				$errors []= "Character <highlight>$name<end> does not exist.";
			} elseif ($channel == "priv" && !isset($this->chatBot->chatlist[$name])) {
				$errors []= "Character <highlight>$name<end> is not in this bot.";
			}

			$nameArray[$key] = "/assist $name";
		}
		if (count($errors)) {
			$sendto->reply(join("\n", $errors));
			return;
		}

		// reverse array so that the first character will be the primary assist, and so on
		$nameArray = array_reverse($nameArray);
		$this->assistMessage = '/macro assist ' . implode(" \\n ", $nameArray);

		$sendto->reply($this->assistMessage);
	}
}
