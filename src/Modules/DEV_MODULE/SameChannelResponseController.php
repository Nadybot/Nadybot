<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	CmdContext,
	CommandManager,
	Nadybot,
};

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'demo',
 *		accessLevel = 'all',
 *		description = 'Execute a command so that links will execute in the same channel',
 *		help        = 'demo.txt'
 *	)
 */
class SameChannelResponseController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public Nadybot $chatBot;

	/**
	 * @HandlesCommand("demo")
	 * @Matches("/^demo (.+)$/si")
	 */
	public function demoCommand(CmdContext $context, string $commandString): void {
		$context->sendto = new DemoResponseCommandReply($context->channel, $context->sendto, $this->chatBot->char->name);
		$context->message = $commandString;
		$this->commandManager->processCmd($context);
	}
}
