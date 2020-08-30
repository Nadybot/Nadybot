<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandManager;
use Nadybot\Core\CommandReply;

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
	 * @Matches("/^htmldecode (.+)$/is")
	 */
	public function htmldecodeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$command = html_entity_decode($args[1], ENT_QUOTES);
		$this->commandManager->process($channel, $command, $sender, $sendto);
	}
}
