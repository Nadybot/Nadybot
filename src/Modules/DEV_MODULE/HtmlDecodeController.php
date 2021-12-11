<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CmdContext;
use Nadybot\Core\CommandManager;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'htmldecode',
 *		accessLevel = 'all',
 *		description = 'Execute a command by first decoding html entities',
 *		help        = 'htmldecode.txt'
 *	)
 */
class HtmlDecodeController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandManager $commandManager;

	/**
	 * @HandlesCommand("htmldecode")
	 */
	public function htmldecodeCommand(CmdContext $context, string $command): void {
		$context->message = html_entity_decode($command, ENT_QUOTES);
		$this->commandManager->processCmd($context);
	}
}
