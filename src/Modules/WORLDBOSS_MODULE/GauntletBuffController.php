<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use function Amp\delay;
use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use DateTime;
use Exception;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	EventManager,
	MessageEmitter,
	MessageHub,
	ModuleInstance,
	Nadybot,
	ParamClass\PDuration,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Modules\TIMERS_MODULE\{
	Alert,
	Timer,
	TimerController,
};
use Nadybot\Modules\WEBSERVER_MODULE\StatsController;
use Psr\Log\LoggerInterface;
use Safe\Exceptions\JsonException;
use Throwable;

/**
 * @author Equi
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "gaubuff",
		accessLevel: "guest",
		description: "Show timer for gauntlet buff",
	),
	NCA\DefineCommand(
		command: "gaubuff set/update",
		accessLevel: "member",
		description: "Set/update timer for gauntlet buff",
	),
	NCA\ProvidesEvent(
		event: "sync(gaubuff)",
		desc: "Triggered when someone sets the gauntlet buff for either side",
	)
]
class GauntletBuffController extends ModuleInstance implements MessageEmitter {
	public const SIDE_NONE = 'none';
	public const GAUNTLET_API = "https://timers.aobots.org/api/v1.1/gaubuffs";

	/** Times to display gaubuff timer alerts */
	#[NCA\Setting\Text(
		options: ["30m 10m"],
		help: 'gau_times.txt',
	)]
	public string $gaubuffTimes = "30m 10m";

	/** Show gaubuff timer on logon */
	#[NCA\Setting\Boolean]
	public bool $gaubuffLogon = true;

	/** Gauntlet buff side if none specified for gaubuff */
	#[NCA\Setting\Options(options: ["none", "clan", "omni"])]
	public string $gaubuffDefaultSide = "none";

	/** Message to send when the Gauntlet buff is expiring soon */
	#[NCA\Setting\Template(
		options: [
			"{c-side} Gauntlet buff runs out in {c-duration}.",
		],
		help: "gauntlet_expiry_warning.txt",
		exampleValues: [
			"side" => "Clan",
			"c-side" => "<clan>Clan<end>",
			"duration" => "15 minutes",
			"c-duration" => "<highlight>15 minutes<end>",
		],
	)]
	public string $gauntletExpiryWarning = "{c-side} Gauntlet buff runs out in {c-duration}.";

	/** Message to send when the Gauntlet buff has expired */
	#[NCA\Setting\Template(
		options: [
			"{c-side} Gauntlet buff <highlight>expired<end>.",
		],
		help: "gauntlet_expired_notification.txt",
		exampleValues: [
			"side" => "Clan",
			"c-side" => "<clan>Clan<end>",
		],
	)]
	public string $gauntletExpiredNotification = "{c-side} Gauntlet buff <highlight>expired<end>.";

	/** Message to send when the Gauntlet buff is set */
	#[NCA\Setting\Template(
		options: [
			"Gauntletbuff timer for {c-side} has been set and expires at {c-expiry}.",
			"Gauntletbuff timer for {c-side} has been set and expires in {c-duration}.",
		],
		help: "gauntlet_set_notification.txt",
		exampleValues: [
			"side" => "Clan",
			"c-side" => "<clan>Clan<end>",
			"expiry" => "Mon, 15:00 UTC (01-Jan-2023)",
			"c-expiry" => "<highlight>Mon, 15:00 UTC (01-Jan-2023)<end>",
			"duration" => "30 minutes",
			"c-duration" => "<highlight>30 minutes<end>",
		],
	)]
	public string $gauntletSetNotification = "Gauntletbuff timer for {c-side} has been set and expires at {c-expiry}.";

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private TimerController $timerController;

	#[NCA\Inject]
	private StatsController $statsController;

	private int $apiRetriesLeft = 3;

	public function getChannelName(): string {
		return Source::SYSTEM . "(gauntlet-buff)";
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->messageHub->registerMessageEmitter($this);
		$this->statsController->registerProvider(new GauntletBuffStats($this, "clan"), "states");
		$this->statsController->registerProvider(new GauntletBuffStats($this, "omni"), "states");
	}

	#[NCA\Event(
		name: "connect",
		description: "Get active Gauntlet buffs from API"
	)]
	public function loadGauntletBuffsFromAPI(): void {
		$client = $this->builder->build();

		try {
			$response = $client->request(new Request(static::GAUNTLET_API));
			$code = $response->getStatus();
			if ($code >= 500 && $code < 600 && --$this->apiRetriesLeft) {
				$this->logger->warning('Gauntlett buff API sent a {code}, retrying in 5s', [
					"code" => $code,
				]);
				delay(5);
				$this->loadGauntletBuffsFromAPI();
				return;
			}
			if ($code !== 200) {
				$this->logger->error('Gauntlet buff API replied with error {code} ({reason})', [
					"code" => $code,
					"reason" => $response->getReason(),
					"headers" => $response->getHeaderPairs(),
				]);
				return;
			}

			$body = $response->getBody()->buffer();
		} catch (Throwable $error) {
			$this->logger->warning('Unknown error from Gauntlet buff API: {error}', [
				"error" => $error->getMessage(),
				"Exception" => $error,
			]);
			return;
		}
		$this->handleGauntletBuffsFromApi($body);
	}

	#[NCA\SettingChangeHandler('gaubuff_times')]
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

	public function setGaubuff(string $side, int $time, string $creator, int $createtime): void {
		$alerts = [];
		$alertTimes = [];
		$gaubuffTimes = $this->gaubuffTimes;
		foreach (explode(' ', $gaubuffTimes) as $utime) {
			$alertTimes [] = $this->util->parseTime($utime);
		}
		$alertTimes []= 0; // timer runs out
		$tokens = [
			"side" => ucfirst($side),
			"c-side" => "<{$side}>" . ucfirst($side) . "<end>",
		];
		foreach ($alertTimes as $alertTime) {
			if (($time - $alertTime) > time()) {
				$alert = new Alert();
				$alert->time = $time - $alertTime;
				if ($alertTime === 0) {
					$alert->message = $this->text->renderPlaceholders($this->gauntletExpiredNotification, $tokens);
				} else {
					$tokens["expiry"] = $this->tmTime($time);
					$tokens["duration"] = $this->util->unixtimeToReadable($alertTime);
					$tokens["c-expiry"] = "<highlight>" . $tokens["expiry"] . "<end>";
					$tokens["c-duration"] = "<highlight>" . $tokens["duration"] . "<end>";
					$alert->message = $this->text->renderPlaceholders($this->gauntletExpiryWarning, $tokens);
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
			$this->config->main->character,
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

	#[NCA\Event(
		name: "logOn",
		description: "Sends gaubuff message on logon"
	)]
	public function gaubufflogonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;
		if (!$this->chatBot->isReady()
			|| !is_string($sender)
			|| (!isset($this->chatBot->guildmembers[$sender]))
			|| !$this->gaubuffLogon
			|| $eventObj->wasOnline !== false
		) {
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
		if ($this->gaubuffLogon && is_string($sender)) {
			$this->showGauntletBuff($sender);
		}
	}

	/** Show the current Gauntlet buff timer, optionally for a given faction only */
	#[NCA\HandlesCommand("gaubuff")]
	public function gaubuffCommand(
		CmdContext $context,
		#[NCA\StrChoice("clan", "omni")]
		?string $buffSide
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

	/** Set the Gauntlet buff timer for your default faction or the given one */
	#[NCA\HandlesCommand("gaubuff set/update")]
	#[NCA\Help\Example("<symbol>gaubuff 14h")]
	#[NCA\Help\Example("<symbol>gaubuff clan 10h15m")]
	public function gaubuffSetCommand(
		CmdContext $context,
		#[NCA\StrChoice("clan", "omni")]
		?string $faction,
		PDuration $duration
	): void {
		$defaultSide = $this->gaubuffDefaultSide;
		$faction ??= $defaultSide;
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
		$tokens = [
			"side" => ucfirst(strtolower($faction)),
			"c-side" => "<" . strtolower($faction) . ">" . ucfirst(strtolower($faction)) . "<end>",
			"expiry" => $this->tmTime($buffEnds),
			"duration" => $this->util->unixtimeToReadable($buffEnds - time()),
		];
		$tokens["c-expiry"] = "<highlight>" . $tokens["expiry"] . "<end>";
		$tokens["c-duration"] = "<highlight>" . $tokens["duration"] . "<end>";
		$msg = $this->text->renderPlaceholders($this->gauntletSetNotification, $tokens);
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

	#[
		NCA\NewsTile(
			name: "gauntlet-buff",
			description: "Show the remaining time of the currently popped Gauntlet buff(s) - if any",
			example: "<header2>Gauntlet buff<end>\n".
				"<tab><omni>Omni Gauntlet buff<end> runs out in <highlight>4 hrs 59 mins 31 secs<end>."
		)
	]
	public function gauntletBuffNewsTile(string $sender): ?string {
		$buffLine = $this->getGauntletBuffLine();
		if (isset($buffLine)) {
			$buffLine = "<header2>Gauntlet buff<end>\n{$buffLine}";
		}
		return $buffLine;
	}

	public function getGauntletBuffLine(): ?string {
		$defaultSide = $this->gaubuffDefaultSide;
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

	public function getIsActive(string $faction): bool {
		$timer = $this->timerController->get("Gaubuff_{$faction}");
		return $timer !== null && isset($timer->endtime);
	}

	/** Check if the given Gauntlet buff is valid and set or update a timer for it */
	protected function handleApiGauntletBuff(ApiGauntletBuff $buff): void {
		$this->logger->info("Received gauntlet information {gauntlet}", ["gauntlet" => $buff]);
		if ($buff->dimension !== $this->config->main->dimension) {
			return;
		}
		if (!in_array(strtolower($buff->faction), ["omni", "clan"])) {
			$this->logger->warning("Received timer information for unknown faction '{faction}'.", [
				"faction" => $buff->faction,
			]);
			return;
		}
		if ($buff->expires < time()) {
			$this->logger->warning("Received expired timer information for {faction} Gauntlet buff.", [
				"faction" => $buff->faction,
			]);
			return;
		}
		$timer = $this->timerController->get("Gaubuff_{$buff->faction}");
		if (isset($timer) && abs($buff->expires-($timer->endtime??0)) < 10) {
			$this->logger->info(
				"Already existing {faction} buff recent enough. Difference: {delta}s",
				[
					"faction" => $buff->faction,
					"delta" => abs($buff->expires-($timer->endtime??0)),
				]
			);
			return;
		}
		$this->logger->info("Updating {faction} buff from API", ["faction" => $buff->faction]);
		$this->setGaubuff(
			strtolower($buff->faction),
			$buff->expires,
			$this->config->main->character,
			time()
		);
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

	/**
	 * Get a list of array for which to show the gauntlet buff(s)
	 *
	 * @return string[]
	 */
	protected function getSidesToShowBuff(?string $side=null): array {
		$defaultSide = $this->gaubuffDefaultSide;
		$side ??= $defaultSide;
		if ($side === static::SIDE_NONE) {
			return ['clan', 'omni'];
		}
		return [$side];
	}

	/** Parse the Gauntlet buff timer API result and handle each running buff */
	private function handleGauntletBuffsFromApi(string $body): void {
		if (!strlen($body)) {
			$this->logger->error('Gauntlet buff API sent an empty reply');
			return;
		}

		/** @var ApiGauntletBuff[] */
		$buffs = [];
		try {
			$data = json_decode($body, true);
			if (!is_array($data)) {
				throw new JsonException();
			}
			foreach ($data as $gauntletData) {
				$buffs []= new ApiGauntletBuff(...$gauntletData);
			}
		} catch (JsonException) {
			$this->logger->error("Gauntlet buff API sent invalid json.");
			return;
		}
		foreach ($buffs as $buff) {
			$this->handleApiGauntletBuff($buff);
		}
	}

	private function tmTime(int $time): string {
		$gtime = new DateTime();
		$gtime->setTimestamp($time);
		return $gtime->format("D, H:i T (d-M-Y)");
	}
}
