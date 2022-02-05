<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use DateTime;
use Exception;
use JsonException;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	EventManager,
	Http,
	HttpResponse,
	ModuleInstance,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PDuration,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Modules\TIMERS_MODULE\{
	Alert,
	Timer,
	TimerController,
};

/**
 * @author Equi
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "gaubuff",
		accessLevel: "all",
		description: "Handles timer for gauntlet buff",
	),
	NCA\ProvidesEvent(
		event: "sync(gaubuff)",
		desc: "Triggered when someone sets the gauntlet buff for either side",
	)
]
class GauntletBuffController extends ModuleInstance implements MessageEmitter {
	public const SIDE_NONE = 'none';
	public const GAUNTLET_API = "https://timers.aobots.org/api/v1.0/gaubuffs";

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public TimerController $timerController;

	public function getChannelName(): string {
		return Source::SYSTEM . "(gauntlet-buff)";
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'gaubuff_times',
			description: 'Times to display gaubuff timer alerts',
			mode: 'edit',
			type: 'text',
			value: '30m 10m',
			options: '30m 10m',
			intoptions: '',
			accessLevel: 'mod',
			help: 'gau_times.txt'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "gaubuff_logon",
			description: "Show gaubuff timer on logon",
			mode: "edit",
			type: "options",
			value: "1",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "gaubuff_default_side",
			description: "Gauntlet buff side if none specified for gaubuff",
			mode: "edit",
			type: "options",
			value: "none",
			options: "none;clan;omni"
		);
		$this->messageHub->registerMessageEmitter($this);
		$this->settingManager->registerChangeListener(
			"gaubuff_times",
			[$this, "validateGaubuffTimes"]
		);
	}

	#[NCA\Event(
		name: "connect",
		description: "Get active Gauntlet buffs from API"
	)]
	public function loadGauntletBuffsFromAPI(): void {
		$this->http->get(static::GAUNTLET_API)
			->withCallback([$this, "handleGauntletBuffsFromApi"]);
	}

	/**
	 * Parse the Gauntlet buff timer API result and handle each running buff
	 */
	public function handleGauntletBuffsFromApi(HttpResponse $response): void {
		if ($response->headers["status-code"] !== "200" || !isset($response->body)) {
			$this->logger->error('Gauntlet buff API did not send correct data.');
			return;
		}
		/** @var ApiGauntletBuff[] */
		$buffs = [];
		try {
			$data = \Safe\json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				throw new JsonException();
			}
			foreach ($data as $gauntletData) {
				$buffs []= new ApiGauntletBuff($gauntletData);
			}
		} catch (JsonException $e) {
			$this->logger->error("Gauntlet buff API sent invalid json.");
			return;
		}
		foreach ($buffs as $buff) {
			$this->handleApiGauntletBuff($buff);
		}
	}

	/**
	 * Check if the given Gauntlet buff is valid and set or update a timer for it
	 */
	protected function handleApiGauntletBuff(ApiGauntletBuff $buff): void {
		$this->logger->info("Received gauntlet information for {$buff->faction}.");
		if (!in_array(strtolower($buff->faction), ["omni", "clan"])) {
			$this->logger->warning("Received timer information for unknown faction {$buff->faction}.");
			return;
		}
		if ($buff->expires < time()) {
			$this->logger->warning("Received expired timer information for {$buff->faction} Gauntlet buff.");
			return;
		}
		$timer = $this->timerController->get("Gaubuff_{$buff->faction}");
		if (isset($timer) && abs($buff->expires-($timer->endtime??0)) < 10) {
			$this->logger->info(
				"Already existing {$buff->faction} buff recent enough. Difference: ".
				abs($buff->expires-($timer->endtime??0)) . "s"
			);
			return;
		}
		$this->logger->info("Updating {$buff->faction} buff from API");
		$this->setGaubuff(
			strtolower($buff->faction),
			$buff->expires,
			$this->chatBot->char->name,
			time()
		);
	}

	public function validateGaubuffTimes(string $setting, string $old, string $new): void {
		$lastTime = null;
		foreach (explode(' ', $new) as $utime) {
			$secs = $this->util->parseTime($utime);
			if ($secs === 0) {
				throw new Exception("<highlight>{$new}<end> is not a list of budatimes.");
			}
			if (isset($lastTime) && $secs >= $lastTime) {
				throw new Exception("You have to give notification times in descending order.");
			}
			$lastTime = $secs;
		}
	}

	private function tmTime(int $time): string {
		$gtime = new DateTime();
		$gtime->setTimestamp($time);
		return $gtime->format("D, H:i T (d-M-Y)");
	}

	public function setGaubuff(string $side, int $time, string $creator, int $createtime): void {
		$alerts = [];
		$alertTimes = [];
		$gaubuffTimes = $this->settingManager->getString('gaubuff_times');
		if (isset($gaubuffTimes)) {
			foreach (explode(' ', $gaubuffTimes) as $utime) {
				$alertTimes [] = $this->util->parseTime($utime);
			}
		}
		$alertTimes []= 0; //timer runs out
		foreach ($alertTimes as $alertTime) {
			if (($time - $alertTime) > time()) {
				$alert = new Alert();
				$alert->time = $time - $alertTime;
				$alert->message = "<{$side}>" . ucfirst($side) . "<end> Gauntlet buff ";
				if ($alertTime === 0) {
					$alert->message .= "<highlight>expired<end>.";
				} else {
					$alert->message .= "runs out in <highlight>".
						$this->util->unixtimeToReadable($alertTime)."<end>.";
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
			\Safe\json_encode($data)
		);
	}

	public function gaubuffcallback(Timer $timer, Alert $alert): void {
		$rMsg = new RoutableMessage($alert->message);
		$rMsg->appendPath(new Source(
			Source::SYSTEM,
			"gauntlet-buff"
		));
		$this->messageHub->handle($rMsg);
	}

	protected function showGauntletBuff(string $sender): void {
		$sides = $this->getSidesToShowBuff();
		$msgs = [];
		foreach ($sides as $side) {
			$timer = $this->timerController->get("Gaubuff_{$side}");
			if ($timer === null || !isset($timer->endtime)) {
				continue;
			}
			$msgs []= "<{$side}>" . ucfirst($side) . " Gauntlet buff<end> ".
					"runs out in <highlight>".
					$this->util->unixtimeToReadable($timer->endtime - time()).
					"<end>.";
		}
		if (empty($msgs)) {
			return;
		}
		$this->chatBot->sendMassTell(join("\n", $msgs), $sender);
	}

	#[NCA\Event(
		name: "logOn",
		description: "Sends gaubuff message on logon"
	)]
	public function gaubufflogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady()
			|| !is_string($sender)
			|| (!isset($this->chatBot->guildmembers[$sender]))
			|| (!$this->settingManager->getBool('gaubuff_logon'))) {
			return;
		}
		$this->showGauntletBuff($sender);
	}

	#[NCA\Event(
		name: "joinPriv",
		description: "Sends gaubuff message on join"
	)]
	public function privateChannelJoinEvent(AOChatEvent $eventObj): void {
		$sender = $eventObj->sender;
		if ($this->settingManager->getBool('gaubuff_logon') && is_string($sender)) {
			$this->showGauntletBuff($sender);
		}
	}

	/**
	 * Show the current Gauntlet buff timer, optionally for a given faction only
	 */
	#[NCA\HandlesCommand("gaubuff")]
	public function gaubuffCommand(
		CmdContext $context,
		#[NCA\StrChoice("clan", "omni")] ?string $buffSide
	): void {
		$sides = $this->getSidesToShowBuff($buffSide);
		$msgs = [];
		foreach ($sides as $side) {
			$timer = $this->timerController->get("Gaubuff_{$side}");
			if ($timer !== null && isset($timer->endtime)) {
				$gaubuff = $timer->endtime - time();
				$msgs []= "<{$side}>" . ucfirst($side) . " Gauntlet buff<end> runs out ".
					"in <highlight>".$this->util->unixtimeToReadable($gaubuff)."<end>.";
			}
		}
		if (empty($msgs)) {
			if (count($sides) === 1) {
				$context->reply("No <{$sides[0]}>{$sides[0]} Gauntlet buff<end> available.");
			} else {
				$context->reply("No Gauntlet buff available for either side.");
			}
			return;
		}
		$context->reply(join("\n", $msgs));
	}

	/**
	 * Set the Gauntlet buff timer for your default faction or the given one
	 */
	#[NCA\HandlesCommand("gaubuff")]
	#[NCA\Help\Example("<symbol>gaubuff 14h")]
	#[NCA\Help\Example("<symbol>gaubuff clan 10h15m")]
	public function gaubuffSetCommand(
		CmdContext $context,
		#[NCA\StrChoice("clan", "omni")] ?string $faction,
		PDuration $duration
	): void {
		$defaultSide = $this->settingManager->getString('gaubuff_default_side') ?? "none";
		$faction = $faction ?? $defaultSide;
		if ($faction === static::SIDE_NONE) {
			$msg = "You have to specify for which side the buff is: omni or clan";
			$context->reply($msg);
			return;
		}
		$buffEnds = $duration->toSecs();
		if ($buffEnds < 1) {
			$msg = "<highlight>" . $duration() . "<end> is not a valid budatime string.";
			$context->reply($msg);
			return;
		}
		$buffEnds += time();
		$this->setGaubuff($faction, $buffEnds, $context->char->name, time());
		$msg = "Gauntletbuff timer for <{$faction}>{$faction}<end> has been set and expires at <highlight>".$this->tmTime($buffEnds)."<end>.";
		$context->reply($msg);
		$event = new SyncGaubuffEvent();
		$event->expires = $buffEnds;
		$event->faction = strtolower($faction);
		$event->sender = $context->char->name;
		$event->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\Event(
		name: "sync(gaubuff)",
		description: "Sync external gauntlet buff events"
	)]
	public function syncExtGaubuff(SyncGaubuffEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->setGaubuff($event->faction, $event->expires, $event->sender, time());
		$msg = "Gauntletbuff timer for <{$event->faction}>{$event->faction}<end> has ".
			"been set and expires at <highlight>" . $this->tmTime($event->expires).
			"<end>.";
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(
			Source::SYSTEM,
			"gauntlet-buff"
		));
		$this->messageHub->handle($rMsg);
	}

	/**
	 * Get a list of array for which to show the gauntlet buff(s)
	 * @return string[]
	 */
	protected function getSidesToShowBuff(?string $side=null): array {
		$defaultSide = $this->settingManager->getString('gaubuff_default_side') ?? "none";
		$side ??= $defaultSide;
		if ($side === static::SIDE_NONE) {
			return ['clan', 'omni'];
		}
		return [$side];
	}

	#[
		NCA\NewsTile(
			name: "gauntlet-buff",
			description: "Show the remaining time of the currently popped Gauntlet buff(s) - if any",
			example:
				"<header2>Gauntlet buff<end>\n".
				"<tab><omni>Omni Gauntlet buff<end> runs out in <highlight>4 hrs 59 mins 31 secs<end>."
		)
	]
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
			if ($timer !== null && isset($timer->endtime)) {
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
