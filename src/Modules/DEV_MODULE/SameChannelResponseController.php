<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	CommandManager,
	Instance,
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
		help: "demo.txt"
	)
]
class SameChannelResponseController extends Instance {

		#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\HandlesCommand("demo")]
	public function demoCommand(CmdContext $context, string $commandString): void {
		$context->sendto = new DemoResponseCommandReply($context->channel, $context->sendto, $this->chatBot->char->name);
		$context->message = $commandString;
		$this->commandManager->processCmd($context);
	}
}
