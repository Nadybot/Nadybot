<?php

namespace Budabot\Modules\TIMERS_MODULE;

use Budabot\Core\CommandReply;

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
	 * @var string
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/**
	 * @var Stopwatch[] $stopwatches
	 */
	public $stopwatches = [];

	/**
	 * Start a new stopwatch
	 *
	 * @param string                     $message The full command received
	 * @param string                     $channel Where did the command come from (tell, guild, priv)
	 * @param string                     $sender  The name of the user issuing the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to use to reply to
	 * @param string[]                   $args    The arguments to the disc-command
	 * @return void
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+start$/i")
	 */
	public function startStopwatchCommand($message, $channel, $sender, CommandReply $sendto, $args) {
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
	 * @param string                     $message The full command received
	 * @param string                     $channel Where did the command come from (tell, guild, priv)
	 * @param string                     $sender  The name of the user issuing the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to use to reply to
	 * @param string[]                   $args    The arguments to the disc-command
	 * @return void
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+stop$/i")
	 */
	public function stopStopwatchCommand($message, $channel, $sender, CommandReply $sendto, $args) {
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
	 * @param string                     $message The full command received
	 * @param string                     $channel Where did the command come from (tell, guild, priv)
	 * @param string                     $sender  The name of the user issuing the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to use to reply to
	 * @param string[]                   $args    The arguments to the disc-command
	 * @return void
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+lap$/i")
	 * @Matches("/^stopwatch\s+lap(\s+.+)$/i")
	 */
	public function stopwatchLapCommand($message, $channel, $sender, CommandReply $sendto, $args) {
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
	 * @param string                     $message The full command received
	 * @param string                     $channel Where did the command come from (tell, guild, priv)
	 * @param string                     $sender  The name of the user issuing the command
	 * @param \Budabot\Core\CommandReply $sendto  Object to use to reply to
	 * @param string[]                   $args    The arguments to the disc-command
	 * @return void
	 *
	 * @HandlesCommand("stopwatch")
	 * @Matches("/^stopwatch\s+view$/i")
	 * @Matches("/^stopwatch\s+show$/i")
	 */
	public function showStopwatchCommand($message, $channel, $sender, CommandReply $sendto, $args) {
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
