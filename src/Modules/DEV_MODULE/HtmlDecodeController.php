<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	Instance,
};

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "htmldecode",
		accessLevel: "all",
		description: "Execute a command by first decoding html entities",
		help: "htmldecode.txt"
	)
]
class HtmlDecodeController extends Instance {

		#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\HandlesCommand("htmldecode")]
	public function htmldecodeCommand(CmdContext $context, string $command): void {
		$context->message = html_entity_decode($command, ENT_QUOTES);
		$this->commandManager->processCmd($context);
	}
}
