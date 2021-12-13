<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	LoggerWrapper,
	Nadybot,
	QueueInterface,
};
use Nadybot\Core\ParamClass\PCharacter;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "sendtell",
		accessLevel: "superadmin",
		description: "Send a tell to another character from the bot",
		help: "sendtell.txt"
	)
]
class SendTellController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\HandlesCommand("sendtell")]
	public function sendtellCommand(CmdContext $context, PCharacter $name, string $message): void {
		$this->chatBot->getUid(
			$name(),
			function (?int $uid, CmdContext $context, string $name, string $message): void {
				if (!isset($uid)) {
					$context->reply("The character <highlight>{$name}<end> does not exist.");
					return;
				}
				$this->logger->logChat("Out. Msg.", $name, $message);
				$this->chatBot->send_tell($uid, $message, "\0", QueueInterface::PRIORITY_MED);
				$context->reply("Message has been sent to <highlight>{$name}<end>.");
			},
			$context,
			$name(),
			$message
		);
	}
}
