<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use DateTime;
use JsonException;
use Nadybot\Core\{
	CmdContext,
	CommandAlias,
	DB,
	Event,
	EventManager,
	Http,
	HttpResponse,
	LoggerWrapper,
	MessageHub,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'wb',
 *		accessLevel = 'all',
 *		description = 'Show next spawntime(s)',
 *		help        = 'wb.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'tara',
 *		accessLevel = 'all',
 *		description = 'Show next Tarasque spawntime(s)',
 *		help        = 'tara.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'tara .+',
 *		accessLevel = 'member',
 *		description = 'Update, set or delete Tarasque killtimer',
 *		help        = 'tara.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'reaper',
 *		accessLevel = 'all',
 *		description = 'Show next Reaper spawntime(s)',
 *		help        = 'reaper.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'reaper .+',
 *		accessLevel = 'member',
 *		description = 'Update, set or delete Reaper killtimer',
 *		help        = 'reaper.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'loren',
 *		accessLevel = 'all',
 *		description = 'Show next Loren Warr spawntime(s)',
 *		help        = 'loren.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'loren .+',
 *		accessLevel = 'member',
 *		description = 'Update, set or delete Loren Warr killtimer',
 *		help        = 'loren.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'gauntlet',
 *		accessLevel = 'all',
 *		description = 'shows timer of Gauntlet',
 *		help        = 'gauntlet.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'gauntlet .+',
 *		accessLevel = 'member',
 *		description = 'Update or set Gaunlet timer',
 *		help        = 'gauntlet.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'father',
 *		accessLevel = 'all',
 *		description = 'shows timer of Father Time',
 *		help        = 'father.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'father .+',
 *		accessLevel = 'member',
 *		description = 'Update or set Father Time timer',
 *		help        = 'father.txt'
 *	)
 *	@ProvidesEvent(value="sync(worldboss)", desc="Triggered when the spawntime of a worldboss is set manually")
 *	@ProvidesEvent(value="sync(worldboss-delete)", desc="Triggered when the timer for a worldboss is deleted")
 */
class WorldBossController {
	public const WORLDBOSS_API = "https://timers.aobots.org/api/v1.0/bosses";

	public const DB_TABLE = "worldboss_timers_<myname>";

	public const INTERVAL = "interval";
	public const IMMORTAL = "immortal";

	public const TARA = 'Tarasque';
	public const REAPER = 'The Hollow Reaper';
	public const LOREN = 'Loren Warr';
	public const VIZARESH = 'Vizaresh';
	public const FATHER_TIME = 'Father Time';

	public const BOSS_MAP = [
		self::TARA => "tara",
		self::REAPER => "reaper",
		self::LOREN => "loren",
		self::VIZARESH => "vizaresh",
		self::FATHER_TIME => "father-time",
	];

	public const BOSS_DATA = [
		self::TARA => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 30*60,
		],
		self::REAPER => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
		],
		self::LOREN => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
		],
		self::VIZARESH => [
			self::INTERVAL => 17*3600,
			self::IMMORTAL => 420,
		],
		self::FATHER_TIME => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
		],
	];

	public const SPAWN_SHOW = 1;
	public const SPAWN_SHOULD = 2;
	public const SPAWN_EVENT = 3;

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
	public CommandAlias $commandAlias;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public GauntletBuffController $gauntletBuffController;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @var WorldBossTimer[]
	 */
	public array $timers = [];

	/**
	 * Keep track whether the last spawn was manually
	 * set or via world event (true), or just calculated (false)
	 *
	 * @var array<string,bool>
	 */
	private array $lastSpawnPrecise = [];

	private array $sentNotifications = [];

	/** @Setup */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . '/Migrations');
		$this->settingManager->add(
			$this->moduleName,
			"worldboss_show_spawn",
			"How to show spawn and vulnerability events",
			"edit",
			"options",
			"1",
			"Show as if the worldboss had actually spawned.".
				";Show 'should have' messages.".
				";Only show spawn and vulnerability events if set by global events. Don't repeat the timer unless set by a global event.",
			"1;2;3",
			"mod"
		);
		$this->commandAlias->register(
			$this->moduleName,
			"gauntlet update",
			"gauupdate"
		);
		$this->commandAlias->register(
			$this->moduleName,
			"gauntlet update",
			"gauset"
		);
		$this->commandAlias->register(
			$this->moduleName,
			"gauntlet kill",
			"gaukill"
		);
		foreach (static::BOSS_MAP as $long => $boss) {
			if ($boss === "vizaresh") {
				$boss = "gauntlet";
			}
			foreach (["prespawn", "spawn", "vulnerable"] as $event) {
				$emitter = new WorldBossChannel("{$boss}-{$event}");
				$this->messageHub->registerMessageEmitter($emitter);
			}
		}
		$this->reloadWorldBossTimers();
	}

	/**
	 * @Event("connect")
	 * @Description("Get boss timers from timer API")
	 */
	public function loadTimersFromAPI(): void {
		$this->http->get(static::WORLDBOSS_API)
			->withCallback([$this, "handleTimersFromApi"]);
	}

	/**
	 * Parse the incoming data and call the function to handle the
	 * timers if the data is valid.
	 */
	public function handleTimersFromApi(HttpResponse $response): void {
		if ($response->headers["status-code"] !== "200" || !isset($response->body)) {
			$this->logger->error('Worldboss API did not send correct data.');
			return;
		}
		/** @var ApiSpawnData[] */
		$timers = [];
		try {
			$data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($data)) {
				throw new JsonException();
			}
			foreach ($data as $timerData) {
				$timers []= new ApiSpawnData($timerData);
			}
		} catch (JsonException $e) {
			$this->logger->error("Worldboss API sent invalid json.");
			return;
		}
		foreach ($timers as $timer) {
			$this->handleApiTimer($timer);
		}
	}

	/**
	 * Check if the given timer is more accurate than our own stored
	 * information, and if so, update our database and timers.
	 */
	protected function handleApiTimer(ApiSpawnData $timer): void {
		$this->logger->info("Received timer information for {$timer->name}.");
		$map = array_flip(static::BOSS_MAP);
		$map["gauntlet"] = $map["vizaresh"];
		$mobName = $map[$timer->name] ?? null;
		if (!isset($mobName)) {
			$this->logger->warning("Received timer information for unknown boss {$timer->name}.");
			return;
		}
		$ourTimer = $this->getWorldBossTimer($mobName);
		$apiTimer = $this->apiTimerToWorldbossTimer($timer, $mobName);
		if (isset($ourTimer) && $apiTimer->next_spawn <= $ourTimer->next_spawn) {
			return;
		}
		$this->logger->info("Updating {$mobName} timer from API");
		$this->worldBossUpdate(
			new Character("Timer-API"),
			$mobName,
			($apiTimer->next_killable??time()) - time()
		);
	}

	/**
	 * Convert timer information from the API into an actual timer with correct information
	 */
	protected function apiTimerToWorldbossTimer(ApiSpawnData $timer, string $mobName): WorldBossTimer {
		$newTimer = new WorldBossTimer();
		$newTimer->spawn = $timer->last_spawn;
		$newTimer->killable = $timer->last_spawn + static::BOSS_DATA[$mobName][static::IMMORTAL];
		$newTimer->next_killable = $newTimer->killable;
		$newTimer->mob_name = $mobName;
		$newTimer->timer = static::BOSS_DATA[$mobName][static::INTERVAL];
		$this->addNextDates([$newTimer]);
		return $newTimer;
	}

	/**
	 * @param WorldBossTimer[] $timers
	 */
	protected function addNextDates(array $timers): void {
		$showSpawn = $this->settingManager->getInt("worldboss_show_spawn") ?? static::SPAWN_SHOW;
		foreach ($timers as $timer) {
			$invulnerableTime = $timer->killable - $timer->spawn;
			$timer->next_killable = $timer->killable;
			$timer->next_spawn    = $timer->spawn;
			if ($showSpawn === static::SPAWN_EVENT && !$this->lastSpawnPrecise[$timer->mob_name]) {
				continue;
			}
			while ($timer->next_killable <= time()) {
				$timer->next_killable += $timer->timer + $invulnerableTime;
				$timer->next_spawn    += $timer->timer + $invulnerableTime;
			}
		}
		usort($timers, function(WorldBossTimer $a, WorldBossTimer $b) {
			return $a->next_spawn <=> $b->next_spawn;
		});
	}

	public function getWorldBossTimer(string $mobName): ?WorldBossTimer {
		/** @var WorldBossTimer[] */
		$timers = $this->db->table(static::DB_TABLE)
			->where("mob_name", $mobName)
			->asObj(WorldBossTimer::class)
			->toArray();
		if (!count($timers)) {
			return null;
		}
		$this->addNextDates($timers);
		return $timers[0];
	}

	/**
	 * @return WorldBossTimer[]
	 */
	protected function getWorldBossTimers(): array {
		return $this->timers;
	}

	protected function reloadWorldBossTimers(): void {
		/** @var WorldBossTimer[] */
		$timers = $this->db->table(static::DB_TABLE)
			->asObj(WorldBossTimer::class)
			->toArray();
		$this->addNextDates($timers);
		$this->timers = $timers;
	}

	protected function niceTime(int $timestamp): string {
		$time = new DateTime();
		$time->setTimestamp($timestamp);
		return $time->format("D, H:i T (d-M-Y)");
	}

	protected function getNextSpawnsMessage(WorldBossTimer $timer, int $howMany=10): string {
		$multiplicator = $timer->timer + $timer->killable - $timer->spawn;
		$times = [];
		if (isset($timer->next_spawn)) {
			for ($i = 0; $i < $howMany; $i++) {
				$spawnTime = $timer->next_spawn + $i*$multiplicator;
				$times []= $this->niceTime($spawnTime);
			}
		} else {
			$times []= "unknown";
		}
		$msg = "Timer updated".
				" at <highlight>".$this->niceTime($timer->time_submitted)."<end>.\n\n".
				"<header2>Next spawntimes<end>\n".
				"<tab>- ".join("\n<tab>- ", $times);
		return $msg;
	}

	protected function getWorldBossMessage(string $mobName): string {
		$timer = $this->getWorldBossTimer($mobName);
		if ($timer === null) {
			$msg = "I currently don't have an accurate timer for <highlight>{$mobName}<end>.";
			return $msg;
		}
		return $this->formatWorldBossMessage($timer, false);
	}

	public function formatWorldBossMessage(WorldBossTimer $timer, bool $short=true): string {
		$showSpawn = $this->settingManager->getInt("worldboss_show_spawn") ?? 1;
		$nextSpawnsMessage = $this->getNextSpawnsMessage($timer);
		$spawntimes = (array)$this->text->makeBlob("Spawntimes for {$timer->mob_name}", $nextSpawnsMessage);
		if (isset($timer->next_spawn) && time() < $timer->next_spawn) {
			if ($timer->mob_name === static::VIZARESH) {
				$secsDead = time() - (($timer->next_spawn??0) - 61200);
				if ($secsDead < 6*60 + 30) {
					$portalOpen = 6*60 + 30 - $secsDead;
					$portalOpenTime = $this->util->unixtimeToReadable($portalOpen);
					$msg = "The Gauntlet portal will be open for <highlight>{$portalOpenTime}<end>.";
					if (!$short && count($spawntimes)) {
						$msg .= " {$spawntimes[0]}";
					}
					return $msg;
				}
			}
			$timeUntilSpawn = $this->util->unixtimeToReadable($timer->next_spawn-time());
			if ($showSpawn === static::SPAWN_SHOULD) {
				$spawnTimeMessage = " should spawn in <highlight>{$timeUntilSpawn}<end>";
			} else {
				$spawnTimeMessage = " spawns in <highlight>{$timeUntilSpawn}<end>";
			}
			if ($short) {
				return "{$timer->mob_name}{$spawnTimeMessage}.";
			}
		} else {
			if ($showSpawn === static::SPAWN_SHOW || $this->lastSpawnPrecise[$timer->mob_name]) {
				$spawnTimeMessage = " spawned";
			} else {
				$spawnTimeMessage = " should have spawned";
			}
		}

		if (isset($timer->next_killable)) {
			$killTimeMessage = "";
			if ($timer->next_killable > time()) {
				$timeUntilKill = $this->util->unixtimeToReadable($timer->next_killable-time());
				$killTimeMessage = " and will be vulnerable in <highlight>{$timeUntilKill}<end>";
			}
			if ($short) {
				return "{$timer->mob_name}{$spawnTimeMessage}{$killTimeMessage}.";
			}
			$msg = "{$timer->mob_name}${spawnTimeMessage}${killTimeMessage}.";
			if (count($spawntimes)) {
				$msg .= " {$spawntimes[0]}";
			}
			return $msg;
		}
		return "{$timer->mob_name} does currently not have an accurate timer.";
	}

	public function worldBossDeleteCommand(Character $sender, string $mobName): string {
		if ($this->db->table(static::DB_TABLE)
			->where("mob_name", $mobName)
			->delete() === 0
		) {
			return "There is currently no timer for <highlight>$mobName<end>.";
		}
		$this->reloadWorldBossTimers();
		return "The timer for <highlight>$mobName<end> has been deleted.";
	}

	public function worldBossUpdate(Character $sender, string $mobName, int $vulnerable): bool {
		$mobData = static::BOSS_DATA[$mobName] ?? null;
		if (!isset($mobData)) {
			return false;
		}
		if ($vulnerable === 0) {
			$vulnerable += $mobData[static::INTERVAL] + $mobData[static::IMMORTAL];
		}
		$vulnerable += time();
		$this->db->table(static::DB_TABLE)
			->upsert(
				[
					"mob_name" => $mobName,
					"timer" => $mobData[static::INTERVAL],
					"spawn" => $vulnerable - $mobData[static::IMMORTAL],
					"killable" => $vulnerable,
					"time_submitted" => time(),
					"submitter_name" => $sender->name,
				],
				["mob_name"]
			);
		$this->reloadWorldBossTimers();
		return true;
	}

	protected function sendSyncEvent(string $sender, string $mobName, int $immortal, bool $forceSync): void {
		$event = new SyncWorldbossEvent();
		$event->boss = static::BOSS_MAP[$mobName];
		$event->vulnerable = $immortal;
		$event->sender = $sender;
		$event->forceSync = $forceSync;
		$this->eventManager->fireEvent($event);
	}

	protected function sendSyncDeleteEvent(string $sender, string $mobName, bool $forceSync): void {
		$event = new SyncWorldbossDeleteEvent();
		$event->boss = static::BOSS_MAP[$mobName];
		$event->sender = $sender;
		$event->forceSync = $forceSync;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("wb")
	 */
	public function bossCommand(CmdContext $context): void {
		$timers = $this->getWorldBossTimers();
		if (!count($timers)) {
			$msg = "I currently don't have an accurate timer for any boss.";
			$context->reply($msg);
			return;
		}
		$messages = array_map([$this, 'formatWorldBossMessage'], $timers);
		$msg = $messages[0];
		if (count($messages) > 1) {
			$msg = "I'm currently monitoring the following bosses:\n".
				join("\n", $messages);
		}
		$context->reply($msg);
	}

	protected function getMobFromContext(CmdContext $context): string {
		$mobs = array_flip(static::BOSS_MAP);
		$mobs["gauntlet"] = $mobs["vizaresh"];
		$mobs["father"] = $mobs["father-time"];
		return $mobs[explode(" ", strtolower($context->message))[0]];
	}

	/**
	 * @HandlesCommand("tara")
	 * @HandlesCommand("loren")
	 * @HandlesCommand("reaper")
	 * @HandlesCommand("gauntlet")
	 * @HandlesCommand("father")
	 */
	public function bossSpawnCommand(CmdContext $context): void {
		$context->reply($this->getWorldBossMessage($this->getMobFromContext($context)));
	}

	/**
	 * @HandlesCommand("tara .+")
	 * @HandlesCommand("loren .+")
	 * @HandlesCommand("reaper .+")
	 * @HandlesCommand("gauntlet .+")
	 * @HandlesCommand("father .+")
	 * @Mask $action kill
	 */
	public function bossKillCommand(CmdContext $context, string $action): void {
		$boss = $this->getMobFromContext($context);
		$this->worldBossUpdate($context->char, $boss, 0);
		$msg = "The timer for <highlight>{$boss}<end> has been updated.";
		$context->reply($msg);
		$msg = "<highlight>{$boss}<end> has been killed.";
		$this->announceBigBossEvent($boss, $msg, 3);
		$this->sendSyncEvent($context->char->name, $boss, 0, $context->forceSync);
	}

	/**
	 * @HandlesCommand("tara .+")
	 * @HandlesCommand("loren .+")
	 * @HandlesCommand("reaper .+")
	 * @HandlesCommand("gauntlet .+")
	 * @HandlesCommand("father .+")
	 * @Mask $action update
	 */
	public function bossUpdateCommand(CmdContext $context, string $action, PDuration $duration): void {
		$boss = $this->getMobFromContext($context);
		$this->worldBossUpdate($context->char, $boss, $duration->toSecs());
		$msg = "The timer for <highlight>{$boss}<end> has been updated.";
		$context->reply($msg);
		$this->sendSyncEvent($context->char->name, $boss, $duration->toSecs(), $context->forceSync);
	}

	/**
	 * @HandlesCommand("tara .+")
	 * @HandlesCommand("loren .+")
	 * @HandlesCommand("reaper .+")
	 * @HandlesCommand("father .+")
	 */
	public function bossDeleteCommand(CmdContext $context, PRemove $action): void {
		$boss = $this->getMobFromContext($context);
		$msg = $this->worldBossDeleteCommand($context->char, $boss);
		$context->reply($msg);
		$this->sendSyncDeleteEvent($context->char->name, $boss, $context->forceSync);
	}

	/**
	 * Announce an event
	 *
	 * @param string $msg The message to send
	 * @param int $step 1 => spawns soon, 2 => has spawned, 3 => vulnerable
	 * @return void
	 */
	protected function announceBigBossEvent(string $boss, string $msg, int $step): void {
		if (time() - ($this->sentNotifications[$boss][$step]??0) < 30) {
			return;
		}
		$this->sentNotifications[$boss] ??= [];
		$this->sentNotifications[$boss][$step] = time();
		$event = 'spawn';
		if ($step === 1) {
			$event = 'prespawn';
		} elseif ($step === 3) {
			$event = 'vulnerable';
		}
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(
			"spawn",
			static::BOSS_MAP[$boss] . "-{$event}",
			join("-", array_map("ucfirst", explode("-", static::BOSS_MAP[$boss])))
		));
		$this->messageHub->handle($rMsg);
	}

	/**
	 * @Event("timer(1sec)")
	 * @Description("Check timer to announce big boss events")
	 */
	public function checkTimerEvent(Event $eventObj, int $interval, bool $manual=false): void {
		$timers = $this->getWorldBossTimers();
		$triggered = false;
		$showSpawn = $this->settingManager->getInt("worldboss_show_spawn") ?? 1;
		foreach ($timers as $timer) {
			$invulnerableTime = $timer->killable - $timer->spawn;
			if ($timer->next_spawn === time()+15*60) {
				$msg = "<highlight>{$timer->mob_name}<end> will spawn in ".
					"<highlight>".$this->util->unixtimeToReadable($timer->next_spawn-time())."<end>.";
				$this->announceBigBossEvent($timer->mob_name, $msg, 1);
				$triggered = true;
			}
			if ($timer->next_spawn === time()) {
				$this->lastSpawnPrecise[$timer->mob_name] = $manual;
				if ($showSpawn === static::SPAWN_EVENT && !$manual) {
					return;
				} elseif ($showSpawn === static::SPAWN_SHOULD && !$manual) {
					$msg = "<highlight>{$timer->mob_name}<end> should spawn ".
						"any time now";
					$invulnDuration = static::BOSS_DATA[$timer->mob_name][static::IMMORTAL];
					if (isset($invulnDuration)) {
						$msg .= " and will be immortal for ".
							$this->util->unixtimeToReadable($invulnDuration);
					}
					$msg .= ".";
				} else {
					$msg = "<highlight>{$timer->mob_name}<end> has spawned";
					if (isset($timer->next_killable) && $timer->next_killable > time()) {
						$msg .= " and will be vulnerable in <highlight>".
							$this->util->unixtimeToReadable($timer->next_killable-time()).
							"<end>.";
					} else {
						$msg .= ".";
					}
				}
				$this->announceBigBossEvent($timer->mob_name, $msg, 2);
				$triggered = true;
			}
			$nextKillTime = time() + $timer->timer + $invulnerableTime;
			if ($timer->next_killable === time() || $timer->next_killable === $nextKillTime) {
				// With this setting, we only want to show "is mortal" when we are 100% sure
				if ($showSpawn === static::SPAWN_EVENT && !$this->lastSpawnPrecise[$timer->mob_name]) {
					return;
				} elseif ($showSpawn === static::SPAWN_SHOULD && !$this->lastSpawnPrecise[$timer->mob_name]) {
					$msg = "<highlight>{$timer->mob_name}<end> should no longer be immortal.";
				} else {
					$msg = "<highlight>{$timer->mob_name}<end> is no longer immortal.";
				}
				$this->announceBigBossEvent($timer->mob_name, $msg, 3);
				$triggered = true;
			}
		}
		if (!$triggered) {
			return;
		}
		$this->addNextDates($this->timers);
	}

	/**
	 * @Event("sync(worldboss)")
	 * @Description("Sync external worldboss timers")
	 */
	public function syncExtWorldbossTimers(SyncWorldbossEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$map = array_flip(static::BOSS_MAP);
		$map["gauntlet"] = $map["vizaresh"];
		$mobName = $map[$event->boss] ?? null;

		if (!isset($mobName)) {
			$this->logger->warning("Received timer update for unknown boss {$event->boss}.");
			return;
		}
		if ($event->vulnerable === 0) {
			$msg = "<highlight>{$mobName}<end> has been killed.";
			$this->announceBigBossEvent($mobName, $msg, 3);
		}
		$this->worldBossUpdate(new Character($event->sender), $mobName, $event->vulnerable);
		$this->checkTimerEvent(new Event(), 1, true);
	}

	/**
	 * @Event("sync(worldboss-delete)")
	 * @Description("Sync external worldboss timer deletes")
	 */
	public function syncExtWorldbossDeletes(SyncWorldbossDeleteEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$map = array_flip(static::BOSS_MAP);
		$map["gauntlet"] = $map["vizaresh"];
		$mobName = $map[$event->boss] ?? null;

		if (!isset($mobName)) {
			$this->logger->warning("Received timer update for unknown boss {$event->boss}.");
			return;
		}
		$this->worldBossDeleteCommand(new Character($event->sender), $mobName);
	}

	/**
	 * @NewsTile("boss-timers")
	 * @Description("A list of upcoming boss spawn timers")
	 * @Example("<header2>Boss timers<end>
	 * <tab>The Hollow Reaper spawns in <highlight>8 hrs 18 mins 26 secs<end>.
	 * <tab>Tarasque spawns in <highlight>8 hrs 1 min 48 secs<end>.
	 * <tab>The Gauntlet portal will be open for <highlight>5 mins 9 secs<end>.")
	 */
	public function bossTimersNewsTile(string $sender, callable $callback): void {
		$timers = $this->getWorldBossTimers();
		if (!count($timers)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Boss timers<end>";
		foreach ($timers as $timer) {
			$blob .= "\n<tab>" . $this->formatWorldBossMessage($timer, true);
		}
		$callback($blob);
	}

	/**
	 * @NewsTile("tara-timer")
	 * @Description("The current tara timer")
	 * @Example("<header2>Tara timer<end>
	 * <tab>Tarasque spawns in <highlight>8 hrs 1 min 48 secs<end>.")
	 */
	public function taraTimerNewsTile(string $sender, callable $callback): void {
		$timer = $this->getWorldBossTimer(static::TARA);
		if (!isset($timer)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Tara timer<end>\n".
			"<tab>" . $this->formatWorldBossMessage($timer, true);
		$callback($blob);
	}

	/**
	 * @NewsTile("gauntlet-timer")
	 * @Description("Show when Vizaresh spawns/is vulnerable")
	 * @Example("<header2>Gauntlet<end>
	 * <tab>The Gauntlet portal will be open for <highlight>5 mins 9 secs<end>.")
	 */
	public function gauntletTimerNewsTile(string $sender, callable $callback): void {
		$timer = $this->getWorldBossTimer(static::VIZARESH);
		if (!isset($timer)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Gauntlet<end>\n".
			"<tab>" . $this->formatWorldBossMessage($timer, true);
		$callback($blob);
	}

	/**
	 * @NewsTile("gauntlet")
	 * @Description("Show when Vizaresh spawns/is vulnerable")
	 * @Example("<header2>Gauntlet<end>
	 * <tab>The Gauntlet portal will be open for <highlight>5 mins 9 secs<end>.
	 * <tab><omni>Omni Gauntlet buff<end> runs out in <highlight>4 hrs 59 mins 31 secs<end>.")
	 */
	public function gauntletNewsTile(string $sender, callable $callback): void {
		$timer = $this->getWorldBossTimer(static::VIZARESH);
		$buffLine = $this->gauntletBuffController->getGauntletBuffLine();
		if (!isset($timer) && !isset($buffLine)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Gauntlet<end>";
		if (isset($timer)) {
			$blob .= "\n<tab>" . $this->formatWorldBossMessage($timer, true);
		}
		if (isset($buffLine)) {
			$blob .= "\n{$buffLine}";
		}
		$callback($blob);
	}
}
