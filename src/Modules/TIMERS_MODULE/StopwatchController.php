<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\CmdContext;
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
	 * @Mask $action start
	 */
	public function startStopwatchCommand(CmdContext $context, string $action): void {
		if (array_key_exists($context->char->name, $this->stopwatches)) {
			$msg = "You already have a stopwatch running. ".
				"Use <highlight><symbol>stopwatch stop<end> to stop it.";
			$context->reply($msg);
			return;
		}
		$this->stopwatches[$context->char->name] = new Stopwatch();
		$msg = "Stopwatch started.";
		$context->reply($msg);
	}

	/**
	 * Stop a user's stopwatch
	 *
	 * @HandlesCommand("stopwatch")
	 * @Mask $action stop
	 */
	public function stopStopwatchCommand(CmdContext $context, string $action): void {
		if (!array_key_exists($context->char->name, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$context->reply($msg);
			return;
		}
		$stopwatch = $this->stopwatches[$context->char->name];
		$stopwatch->end = time();
		unset($this->stopwatches[$context->char->name]);
		$msg = $stopwatch->toString();
		$context->reply("Your stopwatch times:\n$msg");
	}

	/**
	 * Command to add a lap to the stopwatch
	 *
	 * @HandlesCommand("stopwatch")
	 * @Mask $action lap
	 */
	public function stopwatchLapCommand(CmdContext $context, string $action, ?string $lapName): void {
		if (!array_key_exists($context->char->name, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$context->reply($msg);
			return;
		}
		$lapName ??= "";
		$this->stopwatches[$context->char->name]->laps[] = new StopwatchLap(trim($lapName));
		$msg = "Lap added.";
		if (strlen($lapName)) {
			$msg = "Lap <highlight>{$lapName}<end> added.";
		}
		$context->reply($msg);
	}

	/**
	 * Show a user's stopwatch
	 *
	 * @HandlesCommand("stopwatch")
	 * @Mask $action (view|show)
	 */
	public function showStopwatchCommand(CmdContext $context, string $action): void {
		if (!array_key_exists($context->char->name, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$context->reply($msg);
			return;
		}
		$stopwatch = $this->stopwatches[$context->char->name];
		$msg = $stopwatch->toString();
		$context->reply("Your stopwatch times:\n$msg");
	}
}
