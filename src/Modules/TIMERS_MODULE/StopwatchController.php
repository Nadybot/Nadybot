<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * A stopwatch controller with start, stop and lap
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'stopwatch',
 *		accessLevel = 'all',
 *		description = 'stop time difference(s)',
 *		alias       = 'sw',
 *		help        = 'stopwatch.txt'
 *	)
 */
class StopwatchController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @var array<string,Stopwatch>
	 */
	public array $stopwatches = [];

	/**
	 * Start a new stopwatch
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+start$/i")
	 */
	public function startStopwatchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (array_key_exists($sender, $this->stopwatches)) {
			$msg = "You already have a stopwatch running. ".
				"Use <highlight><symbol>stopwatch stop<end> to stop it.";
			$sendto->reply($msg);
			return;
		}
		$this->stopwatches[$sender] = new Stopwatch();
		$msg = "Stopwatch started.";
		$sendto->reply($msg);
	}

	/**
	 * Stop a user's stopwatch
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+stop$/i")
	 */
	public function stopStopwatchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!array_key_exists($sender, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$sendto->reply($msg);
			return;
		}
		$stopwatch = $this->stopwatches[$sender];
		$stopwatch->end = time();
		unset($this->stopwatches[$sender]);
		$msg = $stopwatch->toString();
		$sendto->reply("Your stopwatch times:\n$msg");
	}

	/**
	 * Command to add a lap to the stopwatch
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+lap$/i")
	 * @Matches("/^stopwatch\s+lap(\s+.+)$/i")
	 */
	public function stopwatchLapCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!array_key_exists($sender, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$sendto->reply($msg);
			return;
		}
		$lapName = count($args) > 1 ? $args[1] : "";
		$this->stopwatches[$sender]->laps[] = new StopwatchLap(trim($lapName));
		$msg = "Lap<highlight>{$lapName}<end> added.";
		$sendto->reply($msg);
	}

	/**
	 * Show a user's stopwatch
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+view$/i")
	 * @Matches("/^stopwatch\s+show$/i")
	 */
	public function showStopwatchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!array_key_exists($sender, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$sendto->reply($msg);
			return;
		}
		$stopwatch = $this->stopwatches[$sender];
		$msg = $stopwatch->toString();
		$sendto->reply("Your stopwatch times:\n$msg");
	}
}
