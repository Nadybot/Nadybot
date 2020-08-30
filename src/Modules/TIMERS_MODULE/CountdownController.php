<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Timer;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'countdown',
 *		accessLevel = 'rl',
 *		description = 'Start a 5-second countdown',
 *		help        = 'countdown.txt',
 *		alias		= 'cd'
 *	)
 */
class CountdownController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Timer $timer;
	
	private int $lastCountdown = 0;

	/**
	 * @HandlesCommand("countdown")
	 * @Matches("/^countdown$/i")
	 * @Matches("/^countdown (.+)$/i")
	 */
	public function countdownCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$message = "GO";
		if (count($args) === 2) {
			$message = $args[1];
		}

		if ($this->lastCountdown >= (time() - 30)) {
			$msg = "You can only start a countdown once every 30 seconds.";
			$sendto->reply($msg);
			return;
		}

		$this->lastCountdown = time();

		for ($i = 5; $i > 0; $i--) {
			if ($i > 3) {
				$color = "<red>";
			} elseif ($i > 1) {
				$color = "<orange>";
			} else {
				$color = "<yellow>";
			}
			$msg = "[$color-------&gt; $i &lt;-------<end>]";
			$this->timer->callLater(6-$i, [$sendto, "reply"], $msg);
		}

		$msg = "[<green>------&gt; $message &lt;-------<end>]";
		$this->timer->callLater(6, [$sendto, "reply"], $msg);
	}
}
