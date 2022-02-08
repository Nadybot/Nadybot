<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\CmdContext;
use Nadybot\Core\ModuleInstance;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "unixtime",
		accessLevel: "all",
		description: "Show the date and time for a unix timestamp",
	)
]
class UnixtimeController extends ModuleInstance {
	#[NCA\Inject]
	public Util $util;

	/** Show the date and time for a unix time stamp */
	#[NCA\HandlesCommand("unixtime")]
	public function reloadinstanceAllCommand(CmdContext $context, int $time): void {
		$msg = "$time is <highlight>" . $this->util->date($time) . "<end>.";
		$context->reply($msg);
	}
}
