<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	ModuleInstance,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "htmldecode",
		accessLevel: "all",
		description: "Execute a command by first decoding html entities",
	)
]
class HtmlDecodeController extends ModuleInstance {
	#[NCA\Inject]
	public CommandManager $commandManager;

	/**
	 * Run a command by first decoding html entities
	 *
	 * This is especially useful you need to send special characters to a command but
	 * otherwise can't because the client is encoding them.
	 */
	#[NCA\HandlesCommand("htmldecode")]
	public function htmldecodeCommand(CmdContext $context, string $command): void {
		$context->message = html_entity_decode($command, ENT_QUOTES);
		$this->commandManager->processCmd($context);
	}
}
