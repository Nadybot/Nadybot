<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'unixtime',
		accessLevel: AccessLevel::Guest,
		description: 'Show the date and time for a unix timestamp',
	)
]
class UnixtimeController extends ModuleInstance {
	/** Show the date and time for a unix time stamp */
	#[NCA\HandlesCommand('unixtime')]
	public function reloadinstanceAllCommand(CmdContext $context, int $time): void {
		$msg = "{$time} is <highlight>" . Util::date($time) . '<end>.';
		$context->reply($msg);
	}
}
