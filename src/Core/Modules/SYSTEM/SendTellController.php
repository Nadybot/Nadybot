<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	QueueInterface,
};
use Nadybot\Core\ParamClass\PCharacter;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "sendtell",
		accessLevel: "superadmin",
		description: "Send a tell to another character from the bot",
	)
]
class SendTellController extends ModuleInstance {
		#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public Nadybot $chatBot;

	/** Have the bot send a tell to another */
	#[NCA\HandlesCommand("sendtell")]
	public function sendtellCommand(CmdContext $context, PCharacter $character, string $message): void {
		$this->chatBot->getUid(
			$character(),
			function (?int $uid, CmdContext $context, string $character, string $message): void {
				if (!isset($uid)) {
					$context->reply("The character <highlight>{$character}<end> does not exist.");
					return;
				}
				$this->logger->logChat("Out. Msg.", $character, $message);
				$this->chatBot->send_tell($uid, $message, "\0", QueueInterface::PRIORITY_MED);
				$context->reply("Message has been sent to <highlight>{$character}<end>.");
			},
			$context,
			$character(),
			$message
		);
	}
}
