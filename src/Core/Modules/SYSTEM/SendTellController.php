<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	CommandReply,
	LoggerWrapper,
	Nadybot,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'sendtell',
 *		accessLevel = 'superadmin',
 *		description = 'Send a tell to another character from the bot',
 *		help        = 'sendtell.txt'
 *	)
 */
class SendTellController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public Nadybot $chatBot;
	
	/**
	 * @HandlesCommand("sendtell")
	 * @Matches("/^sendtell ([a-z0-9-]+) (.+)$/i")
	 */
	public function sendtellCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$message = $args[2];
		
		$this->logger->logChat("Out. Msg.", $name, $message);
		$this->chatBot->send_tell($name, $message, "\0", AOC_PRIORITY_MED);
		$sendto->reply("Message has been sent to <highlight>$name<end>.");
	}
}
