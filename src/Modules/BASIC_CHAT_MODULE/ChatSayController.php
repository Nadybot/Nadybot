<?php declare(strict_types=1);

namespace Nadybot\Modules\BASIC_CHAT_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;

/**
 * @Instance
 *
 * @author Legendadv (RK2)
 * @author Derroylo (RK2)
 * @author Marebone (RK2)
 *
 * The ChatSayController class allows user to send messages to either org
 * channel or to private (guest) channel.
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'say',
 *		accessLevel = 'rl',
 *		description = 'Sends message to org chat or private chat',
 *		help        = 'say.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'tell',
 *		accessLevel = 'rl',
 *		description = 'Repeats a message 3 times',
 *      help        = 'tell.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'cmd',
 *		accessLevel = 'rl',
 *		description = 'Creates a highly visible message',
 *      help        = 'cmd.txt'
 *	)
 *	@ProvidesEvent("leadersay")
 *	@ProvidesEvent("leadercmd")
 */
class ChatSayController {

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public ChatLeaderController $chatLeaderController;

	/** @Inject */
	public EventManager $eventManager;

	/**
	 * This command handler sends message to org chat.
	 * @HandlesCommand("say")
	 * @Matches("/^say org (.+)$/si")
	 */
	public function sayOrgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->chatBot->sendGuild("$sender: $args[1]");
		$event = new SayEvent();
		$event->type = "leadersay";
		$event->message = $args[1];
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler sends message to private channel.
	 * @HandlesCommand("say")
	 * @Matches("/^say priv (.+)$/si")
	 */
	public function sayPrivCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$this->chatBot->sendPrivate("$sender: $args[1]");
		$event = new SayEvent();
		$event->type = "leadersay";
		$event->message = $args[1];
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler creates a highly visible message.
	 * @HandlesCommand("cmd")
	 * @Matches("/^cmd (.+)$/si")
	 */
	public function cmdCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		$msg = "\n".
			"<yellow>------------------------------------------<end>\n".
			"<tab><red>$args[1]<end>\n".
			"<yellow>------------------------------------------<end>";

		if ($channel === 'msg') {
			$this->chatBot->sendGuild($msg, true);
			$this->chatBot->sendPrivate($msg, true);
		} else {
			$sendto->reply($msg, true);
		}
		$event = new SayEvent();
		$event->type = "leadercmd";
		$event->message = $args[1];
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler repeats a message 3 times.
	 * @HandlesCommand("tell")
	 * @Matches("/^tell (.+)$/si")
	 */
	public function tellCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!$this->chatLeaderController->checkLeaderAccess($sender)) {
			$sendto->reply("You must be Raid Leader to use this command.");
			return;
		}

		if ($channel === 'guild' || $channel === 'msg') {
			$this->chatBot->sendGuild("<yellow>$args[1]<end>", true);
			$this->chatBot->sendGuild("<yellow>$args[1]<end>", true);
			$this->chatBot->sendGuild("<yellow>$args[1]<end>", true);
		}

		if ($channel === 'priv' || $channel === 'msg') {
			$this->chatBot->sendPrivate("<yellow>$args[1]<end>", true);
			$this->chatBot->sendPrivate("<yellow>$args[1]<end>", true);
			$this->chatBot->sendPrivate("<yellow>$args[1]<end>", true);
		}
	}
}
