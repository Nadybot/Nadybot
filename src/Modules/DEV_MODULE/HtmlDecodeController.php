<?php

namespace Budabot\Modules\DEV_MODULE;

use Budabot\Core\AutoInject;

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
class HtmlDecodeController extends AutoInject {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;
	
	/**
	 * @HandlesCommand("htmldecode")
	 * @Matches("/^htmldecode (.+)$/is")
	 */
	public function htmldecodeCommand($message, $channel, $sender, $sendto, $args) {
		$command = html_entity_decode($args[1], ENT_QUOTES);
		$this->commandManager->process($channel, $command, $sender, $sendto);
	}
}
