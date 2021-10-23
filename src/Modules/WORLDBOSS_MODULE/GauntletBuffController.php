<?php

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use DateTime;
use Nadybot\Core\{
	CmdContext,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	Modules\ALTS\AltsController,
	Nadybot,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PDuration;
use Nadybot\Modules\TIMERS_MODULE\Alert;
use Nadybot\Modules\TIMERS_MODULE\Timer;
use Nadybot\Modules\TIMERS_MODULE\TimerController;

/**
 * @author Equi
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'gaubuff',
 *		accessLevel = 'all',
 *		description = 'Handles timer for gauntlet buff',
 *		help        = 'gaubuff.txt'
 *	)
 */
class GauntletBuffController implements MessageEmitter {
	public const SIDE_NONE = 'none';

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public AltsController $altsController;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public TimerController $timerController;

	public function getChannelName(): string {
		return Source::SYSTEM . "(gauntlet-buff)";
	}

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			'gaubuff_times',
			'Times to display gaubuff timer alerts',
			'edit',
			'text',
			'30m 10m',
			'30m 10m',
			'',
			'mod',
			'gau_times.txt'
		);
		$this->settingManager->add(
			$this->moduleName,
			"gaubuff_logon",
			"Show gaubuff timer on logon",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"gaubuff_default_side",
			"Implicit gauntlet buff side if none specified",
			"edit",
			"options",
			"none",
			"none;clan;omni"
		);
		$this->messageHub->registerMessageEmitter($this);
	}

	private function tmTime($zz) {
		$gtime = new DateTime();
		$gtime->setTimestamp($zz);
		return $gtime->format("D, H:i T (Y-m-d)");
	}

	public function setGaubuff(string $side, string $time, string $creator, int $createtime): void {
		$alerts = [];
		foreach (explode(' ', $this->settingManager->get('gaubuff_times')) as $utime) {
			$alertTimes[] = $this->util->parseTime($utime);
		}
		$alertTimes[] = 0; //timer runs out
		foreach ($alertTimes as $alertTime) {
			if (($time - $alertTime) > time()) {
				$alert = new Alert();
				$alert->time = $time - $alertTime;
				$alert->message = "<{$side}>" . ucfirst($side) . "<end> Gauntlet buff ";
				if ($alertTime === 0) {
					$alert->message .= "<highlight>expired<end>!";
				} else {
					$alert->message .= "runs out in <highlight>".
						$this->util->unixtimeToReadable($alertTime)."<end>!";
				}
				$alerts []= $alert;
			}
		}
		$data = [];
		$data['createtime'] = $createtime;
		$data['creator'] = $creator;
		$data['repeat'] = 0;

		$this->timerController->remove("Gaubuff_{$side}");
		$this->timerController->add(
			"Gaubuff_{$side}",
			$this->chatBot->char->name,
			"",
			$alerts,
			"GauntletBuffController.gaubuffcallback",
			json_encode($data)
		);
	}

	public function gaubuffcallback(Timer $timer, Alert $alert) {
		$rMsg = new RoutableMessage($alert->message);
		$rMsg->appendPath(new Source(
			Source::SYSTEM,
			"gauntlet-buff"
		));
		$this->messageHub->handle($rMsg);
	}

	protected function showGauntletBuff(string $sender): void {
		$sides = $this->getSidesToShowBuff($args['side']??null);
		$msgs = [];
		foreach ($sides as $side) {
			$timer = $this->timerController->get("Gaubuff_{$side}");
			if ($timer === null) {
				continue;
			}
			$msgs []= "<{$side}>" . ucfirst($side) . " Gauntlet buff<end> ".
					"runs out in <highlight>".
					$this->util->unixtimeToReadable($timer->endtime - time()).
					"<end>!";
		}
		if (empty($msgs)) {
			return;
		}
		$this->chatBot->sendMassTell(join("\n", $msgs), $sender);
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends gaubuff message on logon")
	 */
	public function gaubufflogonEvent($eventObj) {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady()
			|| (!isset($this->chatBot->guildmembers[$sender]))
			|| (!$this->settingManager->get('gaubuff_logon'))) {
			return;
		}
		$this->showGauntletBuff($sender);
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Sends gaubuff message on join")
	 */
	public function privateChannelJoinEvent($eventObj) {
		$sender = $eventObj->sender;
		if ($this->settingManager->get('gaubuff_logon')) {
			$this->showGauntletBuff($sender);
		}
	}

	/**
	 * This command handler shows gauntlet.
	 *
	 * @HandlesCommand("gaubuff")
	 */
	public function gaubuffCommand(CmdContext $context, ?string $side="(clan|omni)"): void {
		$sides = $this->getSidesToShowBuff($side??null);
		$msgs = [];
		foreach ($sides as $side) {
			$timer = $this->timerController->get("Gaubuff_{$side}");
			if ($timer !== null) {
				$gaubuff = $timer->endtime - time();
				$msgs []= "<{$side}>" . ucfirst($side) . " Gauntlet buff<end> runs out ".
					"in <highlight>".$this->util->unixtimeToReadable($gaubuff)."<end>.";
			}
		}
		if (empty($msgs)) {
			if (isset($args['side'])) {
				$context->reply("No <{$side}>{$side} Gauntlet buff<end> available!");
			} else {
				$context->reply("No Gauntlet buff available!");
			}
			return;
		}
		$context->reply(join("\n", $msgs));
	}

	/**
	 * This command handler shows gauntlet.
	 *
	 * @HandlesCommand("gaubuff")
	 */
	public function gaubuffSetCommand(CmdContext $context, ?string $side="(clan|omni)", PDuration $time): void {
		$defaultSide = $this->settingManager->getString('gaubuff_default_side');
		if (!isset($side) && isset($time) && $defaultSide === static::SIDE_NONE) {
			$msg = "You have to specify for which side the buff is: omni or clan";
			$context->reply($msg);
			return;
		}
		$side = $side ?? $defaultSide;
		$buffEnds = $time->toSecs();
		if ($buffEnds < 1) {
			$msg = "You must enter a valid time parameter for the gauntlet buff time.";
			$context->reply($msg);
			return;
		}
		$buffEnds += time();
		$this->setGaubuff($side, $buffEnds, $context->char->name, time());
		$msg = "Gauntletbuff timer for <{$side}>{$side}<end> has been set and expires at <highlight>".$this->tmTime($buffEnds)."<end>.";
		$context->reply($msg);
		return;
	}

	/**
	 * Get a list of array for which to show the gauntlet buff(s)
	 * @return string[]
	 */
	protected function getSidesToShowBuff(?string $side): array {
		$defaultSide = $this->settingManager->getString('gaubuff_default_side');
		$side ??= $defaultSide;
		if ($side === static::SIDE_NONE) {
			return ['clan', 'omni'];
		}
		return [$side];
	}

	/**
	 * @NewsTile("gauntlet-buff")
	 * @Description("Show the remaining time of the currently popped Gauntlet buff(s) - if any")
	 * @Example("<header2>Gauntlet buff<end>
	 * <tab><omni>Omni Gauntlet buff<end> runs out in <highlight>4 hrs 59 mins 31 secs<end>.")
	 */
	public function gauntletBuffNewsTile(string $sender, callable $callback): void {
		$buffLine = $this->getGauntletBuffLine();
		if (isset($buffLine)) {
			$buffLine = "<header2>Gauntlet buff<end>\n{$buffLine}";
		}
		$callback($buffLine);
	}

	public function getGauntletBuffLine(): ?string {
		$defaultSide = $this->settingManager->getString('gaubuff_default_side');
		$sides = $this->getSidesToShowBuff(($defaultSide === "none") ? null : $defaultSide);
		$msgs = [];
		foreach ($sides as $side) {
			$timer = $this->timerController->get("Gaubuff_{$side}");
			if ($timer !== null) {
				$gaubuff = $timer->endtime - time();
				$msgs []= "<tab><{$side}>" . ucfirst($side) . " Gauntlet buff<end> runs out ".
					"in <highlight>".$this->util->unixtimeToReadable($gaubuff)."<end>.\n";
			}
		}
		if (empty($msgs)) {
			return null;
		}
		return join("", $msgs);
	}
}
