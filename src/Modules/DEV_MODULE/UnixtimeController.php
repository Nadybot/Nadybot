<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'unixtime',
 *		accessLevel = 'all',
 *		description = 'Show the date and time for a unix timestamp',
 *		help        = 'unixtime.txt'
 *	)
 */
class UnixtimeController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Util $util;

	/**
	 * @HandlesCommand("unixtime")
	 * @Matches("/^unixtime (\d+)$/i")
	 */
	public function reloadinstanceAllCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$time = $args[1];
		
		$msg = "$time is " . $this->util->date($time) . ".";
		$sendto->reply($msg);
	}
}
