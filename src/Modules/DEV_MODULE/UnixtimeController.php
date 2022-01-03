<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\CmdContext;
use Nadybot\Core\Instance;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "unixtime",
		accessLevel: "all",
		description: "Show the date and time for a unix timestamp",
		help: "unixtime.txt"
	)
]
class UnixtimeController extends Instance {

		#[NCA\Inject]
	public Util $util;

	#[NCA\HandlesCommand("unixtime")]
	public function reloadinstanceAllCommand(CmdContext $context, int $time): void {
		$msg = "$time is <highlight>" . $this->util->date($time) . "<end>.";
		$context->reply($msg);
	}
}
