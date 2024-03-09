<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	ModuleInstance,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "demo",
		accessLevel: "guest",
		description: "Execute a command so that links will execute in the same channel",
	)
]
class SameChannelResponseController extends ModuleInstance {
	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private BotConfig $config;

	/**
	 * Run a command and have the bot create all links so they open in the same source
	 * where the '<symbol>demo'-command was run. Only works in orgchat and private channels
	 */
	#[NCA\HandlesCommand("demo")]
	public function demoCommand(CmdContext $context, string $commandString): void {
		if (!isset($context->source)) {
			return;
		}
		$context->sendto = new DemoResponseCommandReply($context->source, $context->sendto, $this->config->main->character);
		$context->message = $commandString;
		$this->commandManager->processCmd($context);
	}
}
