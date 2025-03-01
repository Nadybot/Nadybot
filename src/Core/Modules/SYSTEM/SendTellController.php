<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use AO\SendPriority;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	Types\AccessLevel,
};
use Psr\Log\LoggerInterface;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'sendtell',
		accessLevel: AccessLevel::Superadmin,
		description: 'Send a tell to another character from the bot',
	)
]
class SendTellController extends ModuleInstance {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	/** Have the bot send a tell to a character */
	#[NCA\HandlesCommand('sendtell')]
	public function sendtellCommand(CmdContext $context, PCharacter $character, string $message): void {
		$uid = $this->chatBot->getUid($character());
		if (!isset($uid)) {
			$context->reply("The character <highlight>{$character}<end> does not exist.");
			return;
		}
		if ($this->logger instanceof LoggerWrapper) {
			$this->logger->logChat('Out. Msg.', $character(), $message);
		}
		$this->chatBot->sendRawTell($uid, $message, SendPriority::Medium);
		$context->reply("Message has been sent to <highlight>{$character}<end>.");
	}
}
