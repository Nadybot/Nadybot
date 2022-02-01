<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	CommandManager,
	ModuleInstance,
	Nadybot,
};

/**
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "demo",
		accessLevel: "all",
		description: "Execute a command so that links will execute in the same channel",
	)
]
class SameChannelResponseController extends ModuleInstance {

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	/**
	 * Run a command and have the bot create all links so they open in the same source
	 * where the '<symbol>demo'-command was run. Only works in orgchat and private channels
	 */
	#[NCA\HandlesCommand("demo")]
	public function demoCommand(CmdContext $context, string $commandString): void {
		if (!isset($context->source)) {
			return;
		}
		$context->sendto = new DemoResponseCommandReply($context->source, $context->sendto, $this->chatBot->char->name);
		$context->message = $commandString;
		$this->commandManager->processCmd($context);
	}
}
