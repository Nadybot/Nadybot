<?php declare(strict_types=1);

namespace Nadybot\Modules\TIMERS_MODULE;

use DateTime;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	LoggerWrapper,
	ModuleInstance,
	Text,
	Util,
};

/**
 * A stopwatch controller with start, stop and lap
 *
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "stopwatch",
		accessLevel: "guest",
		description: "stop time difference(s)",
		alias: "sw"
	)
]
class StopwatchController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<string,Stopwatch> */
	public array $stopwatches = [];

	/** Start a new stopwatch for yourself */
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

	/** Stop your stopwatch and show the time elapsed and the laps */
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
		$context->reply("Your stopwatch times:\n{$msg}");
	}

	/** Add a lap with an optional name to your stopwatch */
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

	/** View the current times on your stopwatch without stopping it */
	#[NCA\HandlesCommand("stopwatch")]
	public function showStopwatchCommand(CmdContext $context, #[NCA\Str("view", "show")] string $action): void {
		if (!array_key_exists($context->char->name, $this->stopwatches)) {
			$msg = "You don't have a stopwatch running.";
			$context->reply($msg);
			return;
		}
		$stopwatch = $this->stopwatches[$context->char->name];
		$msg = $stopwatch->toString();
		$context->reply("Your stopwatch times:\n{$msg}");
	}
}
