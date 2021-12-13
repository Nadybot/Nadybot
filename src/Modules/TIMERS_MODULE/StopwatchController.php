<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use Nadybot\Core\Attributes as NCA;
use DateTime;
use Nadybot\Core\CmdContext;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * A stopwatch controller with start, stop and lap
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "stopwatch",
		accessLevel: "all",
		description: "stop time difference(s)",
		help: "stopwatch.txt",
		alias: "sw"
	)
]
class StopwatchController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/**
	 * @var array<string,Stopwatch>
	 */
	public array $stopwatches = [];

	/**
	 * Start a new stopwatch
	 */
	#[NCA\HandlesCommand("stopwatch")]
	public function startStopwatchCommand(CmdContext $context, #[NCA\Str("start")] string $action): void {
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
	 */
	#[NCA\HandlesCommand("stopwatch")]
	public function stopStopwatchCommand(CmdContext $context, #[NCA\Str("stop")] string $action): void {
		if (!array_key_exists($context->char->name, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$context->reply($msg);
			return;
		}
		$stopwatch = $this->stopwatches[$context->char->name];
		$stopwatch->end = new DateTime();
		unset($this->stopwatches[$context->char->name]);
		$msg = $stopwatch->toString();
		$context->reply("Your stopwatch times:\n$msg");
	}

	/**
	 * Command to add a lap to the stopwatch
	 */
	#[NCA\HandlesCommand("stopwatch")]
	public function stopwatchLapCommand(CmdContext $context, #[NCA\Str("lap")] string $action, ?string $lapName): void {
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
	 */
	#[NCA\HandlesCommand("stopwatch")]
	public function showStopwatchCommand(CmdContext $context, #[NCA\Regexp("view|show")] string $action): void {
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
