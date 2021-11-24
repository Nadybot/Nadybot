<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CmdContext;
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
	 */
	public function reloadinstanceAllCommand(CmdContext $context, int $time): void {
		$msg = "$time is <highlight>" . $this->util->date($time) . "<end>.";
		$context->reply($msg);
	}
}
