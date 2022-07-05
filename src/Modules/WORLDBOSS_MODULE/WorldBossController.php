<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use function Amp\delay;
use function Safe\json_decode;

use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use DateTime;
use DateTimeZone;
use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	ConfigFile,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	MessageHub,
	ModuleInstance,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
	UserException,
	Util,
};
use Nadybot\Modules\HELPBOT_MODULE\{PlayfieldController, Timezone};
use Safe\Exceptions\JsonException;
use Throwable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "wb",
		accessLevel: "guest",
		description: "Show next spawntime(s)",
	),
	NCA\DefineCommand(
		command: "tara",
		accessLevel: "guest",
		description: "Show next Tarasque spawntime(s)",
	),
	NCA\DefineCommand(
		command: WorldBossController::CMD_TARA_UPDATE,
		accessLevel: "member",
		description: "Update, set or delete Tarasque killtimer",
	),
	NCA\DefineCommand(
		command: "reaper",
		accessLevel: "guest",
		description: "Show next Reaper spawntime(s)",
	),
	NCA\DefineCommand(
		command: WorldBossController::CMD_REAPER_UPDATE,
		accessLevel: "member",
		description: "Update, set or delete Reaper killtimer",
	),
	NCA\DefineCommand(
		command: "loren",
		accessLevel: "guest",
		description: "Show next Loren Warr spawntime(s)",
	),
	NCA\DefineCommand(
		command: WorldBossController::CMD_LOREN_UPDATE,
		accessLevel: "member",
		description: "Update, set or delete Loren Warr killtimer",
	),
	NCA\DefineCommand(
		command: "gauntlet",
		accessLevel: "guest",
		description: "shows timer of Gauntlet",
	),
	NCA\DefineCommand(
		command: WorldBossController::CMD_GAUNTLET_UPDATE,
		accessLevel: "member",
		description: "Update or set Gaunlet timer",
	),
	NCA\DefineCommand(
		command: "father",
		accessLevel: "guest",
		description: "shows timer of Father Time",
	),
	NCA\DefineCommand(
		command: WorldBossController::CMD_FATHER_UPDATE,
		accessLevel: "member",
		description: "Update or set Father Time timer",
	),
	NCA\DefineCommand(
		command: "updatewb",
		accessLevel: "mod",
		description: "(re)-fetch current worldboss-timers from the API",
	),
	NCA\ProvidesEvent(
		event: "sync(worldboss)",
		desc: "Triggered when the spawntime of a worldboss is set manually",
	),
	NCA\ProvidesEvent(
		event: "sync(worldboss-delete)",
		desc: "Triggered when the timer for a worldboss is deleted",
	)
]
class WorldBossController extends ModuleInstance {
	public const CMD_TARA_UPDATE = "tara set/delete";
	public const CMD_FATHER_UPDATE = "father set/delete";
	public const CMD_GAUNTLET_UPDATE = "gauntlet set/delete";
	public const CMD_LOREN_UPDATE = "loren set/delete";
	public const CMD_REAPER_UPDATE = "reaper set/delete";

	public const WORLDBOSS_API = "https://timers.aobots.org/api/v1.1/bosses";

	public const DB_TABLE = "worldboss_timers_<myname>";

	public const INTERVAL = "interval";
	public const IMMORTAL = "immortal";
	public const COORDS = "coordinates";
	public const INTERVAL2 = "usual_interval";
	public const CHANCE = "spawn_chance";
	public const AOU = "aou_link";

	public const TARA = 'Tarasque';
	public const REAPER = 'The Hollow Reaper';
	public const LOREN = 'Loren Warr';
	public const VIZARESH = 'Vizaresh';
	public const FATHER_TIME = 'Father Time';
	public const DESERT_RIDER = 'The Desert Rider';
	public const ZAAL = 'Zaal The Immortal';
	public const CERUBIN = 'Cerubin The Reborn';
	public const TAM = 'T.A.M.';
	public const ATMA = 'Atma';
	public const ABMOUTH = 'Abmouth Indomitus';

	public const BOSS_MAP = [
		self::TARA => "tara",
		self::REAPER => "reaper",
		self::LOREN => "loren",
		self::VIZARESH => "vizaresh",
		self::FATHER_TIME => "father-time",
		self::ZAAL => "zaal",
		self::DESERT_RIDER => "desert-rider",
		self::CERUBIN => "cerubin",
		self::TAM => "tam",
		self::ATMA => "atma",
		self::ABMOUTH => "abmouth",
	];

	public const BOSS_DATA = [
		self::TARA => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 30*60,
			self::COORDS => [2092, 3822, 505],
			self::AOU => 148,
		],
		self::REAPER => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
			self::COORDS => [1760, 2840, 595],
			self::AOU => 746,
		],
		self::LOREN => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
			self::COORDS => [350, 500, 567],
			self::AOU => 743,
		],
		self::VIZARESH => [
			self::INTERVAL => 17*3600,
			self::IMMORTAL => 420,
			self::COORDS => [299, 28, 4328],
			self::AOU => 615,
		],
		self::FATHER_TIME => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
			self::AOU => 791,
		],
		self::ZAAL => [
			self::IMMORTAL => 15*60,
			self::INTERVAL2 => 6*3600,
			self::CHANCE => 75,
			self::COORDS => [1730, 1200, 610],
			self::AOU => 730,
		],
		self::CERUBIN => [
			self::IMMORTAL => 15*60,
			self::INTERVAL2 => 9*3600,
			self::CHANCE => 85,
			self::COORDS => [2100, 280, 505],
			self::AOU => 730,
		],
		self::TAM => [
			self::IMMORTAL => 15*60,
			self::INTERVAL2 => 6*3600,
			self::CHANCE => 60,
			self::COORDS => [1130, 1530, 795],
			self::AOU => 730,
		],
		self::ATMA => [
			self::IMMORTAL => 15*60,
			self::INTERVAL2 => 3*3600,
			self::CHANCE => 30,
			self::COORDS => [1900, 3000, 650],
			self::AOU => 730,
		],
		self::ABMOUTH => [
			self::IMMORTAL => 15*60,
			self::COORDS => [3150, 1550, 556],
			self::AOU => 730,
		],
		self::DESERT_RIDER => [
			self::IMMORTAL => 15*60,
			self::COORDS => [2230, 1580, 565],
			self::AOU => 769,
		],
	];

	public const SPAWN_SHOW = 1;
	public const SPAWN_SHOULD = 2;
	public const SPAWN_EVENT = 3;

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public PlayfieldController $pfController;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public GauntletBuffController $gauntletBuffController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Logger]
	public LoggerWrapper $logger;


	/** How to show spawn and vulnerability events */
	#[NCA\Setting\Options(options: [
		"Show as if the worldboss had actually spawned" => self::SPAWN_SHOW,
		"Show 'should have' messages" => self::SPAWN_SHOULD,
		"Only show spawn and vulnerability events if set by global events and ".
		"don't repeat the timer unless set by a global event" => self::SPAWN_EVENT,
	])]
	public int $worldbossShowSpawn = self::SPAWN_EVENT;

	/** @var WorldBossTimer[] */
	public array $timers = [];

	/**
	 * Keep track whether the last spawn was manually
	 * set or via world event (true), or just calculated (false)
	 *
	 * @var array<string,bool>
	 */
	private array $lastSpawnPrecise = [];

	/** @var array<string,array<int,int>> */
	private array $sentNotifications = [];

	private int $timerRetriesLeft = 3;

	#[NCA\Setup]
	public function setup(): void {
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
				if ($event !== "prespawn" || isset(self::BOSS_DATA[$long][self::INTERVAL])) {
					$emitter = new WorldBossChannel("{$boss}-{$event}");
					$this->messageHub->registerMessageEmitter($emitter);
				}
			}
		}
		$this->reloadWorldBossTimers();
	}

	#[NCA\HandlesCommand("updatewb")]
	public function updateWbCommand(CmdContext $context): Generator {
		try {
			$numUpdates = yield from $this->loadTimersFromAPI();
			$context->reply(
				"Timer data successfully loaded from the API. {$numUpdates} ".
				$this->text->pluralize("timer", $numUpdates) . " updated."
			);
		} catch (UserException $e) {
			$context->reply($e->getMessage());
		} catch (Throwable $e) {
			$this->logger->error("Error updating timers: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			$context->reply("An error occurred when updating the timers. Please check the logs.");
		}
	}

	#[NCA\Event(
		name: "connect",
		description: "Get boss timers from timer API"
	)]
	public function loadTimersFromAPI(): Generator {
		$client = $this->builder->build();

		try {
			/** @var Response */
			$response = yield $client->request(new Request(static::WORLDBOSS_API));
			$code = $response->getStatus();
			if ($code >= 500 && $code < 600 && --$this->timerRetriesLeft) {
				$this->logger->warning('Worldboss API sent a {code}, retrying in 5s', [
					"code" => $code,
				]);
				yield delay(5000);
				return $this->loadTimersFromAPI();
			}
			if ($code !== 200) {
				$this->logger->error('Worldboss API replied with error {code} ({reason})', [
					"code" => $code,
					"reason" => $response->getReason(),
					"headers" => $response->getRawHeaders(),
				]);
				return 0;
			}

			/** @var string */
			$body = yield $response->getBody()->buffer();
		} catch (Throwable $error) {
			$this->logger->warning('Unknown error from Worldboss API: {error}', [
				"error" => $error->getMessage(),
				"Exception" => $error,
			]);
			return 0;
		}
		return $this->handleTimerData($body);
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
		$timers = $this->addNextDates($timers);
		return $timers[0];
	}

	public function formatWorldBossMessage(WorldBossTimer $timer, bool $short=true, bool $startpage=false): string {
		$showSpawn = $this->worldbossShowSpawn;
		$nextSpawnsMessage = $this->getNextSpawnsMessage($timer);
		$spawntimes = (array)$this->text->makeBlob("Spawntimes for {$timer->mob_name}", $nextSpawnsMessage);

		/** @phpstan-var null|array{int,int,int} */
		$coords = self::BOSS_DATA[$timer->mob_name][self::COORDS] ?? null;
		$mobName = $timer->mob_name;
		if (isset($coords) && !$startpage) {
			$pf = $this->pfController->getPlayfieldById($coords[2]);
			if (isset($pf)) {
				$wpLink = $this->text->makeChatcmd(
					$pf->long_name,
					"/waypoint {$coords[0]} {$coords[1]} {$coords[2]}"
				);
				$blob = $timer->mob_name . " is in [{$wpLink}]";
				$aou = self::BOSS_DATA[$timer->mob_name][self::AOU] ?? null;
				if (isset($aou)) {
					$blob .= "\nMore info: [".
						$this->text->makeChatcmd("guide", "/tell <myname> aou {$aou}").
						"] [".
						$this->text->makeChatcmd("see AO-Universe", "/start https://www.ao-universe.com/guides/{$aou}").
						"]";
				}
				$mobName = ((array)$this->text->makeBlob(
					$timer->mob_name,
					$blob,
					"Waypoint for {$timer->mob_name}",
				))[0];
			}
		}
		if (isset($timer->next_spawn) && time() < $timer->next_spawn) {
			if ($timer->mob_name === static::VIZARESH) {
				$secsDead = time() - ($timer->next_spawn - 61200);
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
				return "{$mobName}{$spawnTimeMessage}.";
			}
		} elseif ($timer->killable < time() && !isset(self::BOSS_DATA[$timer->mob_name][self::INTERVAL])) {
			$days = (int)floor((time() - $timer->spawn) / (24*3600));
			$spawnTimeMessage = " last spawn <grey>";
			if ($days > 0) {
				$spawnTimeMessage .= "{$days}d ";
			}
			$intTime = new DateTime('now', new DateTimeZone('UTC'));
			$intTime->setTimestamp((time() - $timer->spawn) % (24 * 3600));
			$spawnTimeMessage .= preg_replace("/^0h /", "", str_replace(" 0", " ", $intTime->format("G\\h i\\m"))) . " ago<end>";
			$usualSpawnInterval = self::BOSS_DATA[$timer->mob_name][self::INTERVAL2] ?? null;
			if (isset($usualSpawnInterval)) {
				$spawnChance = self::BOSS_DATA[$timer->mob_name][self::CHANCE] ?? null;
				if (isset($spawnChance)) {
					$spawnTimeMessage .= ". Spawns every " . $this->util->unixtimeToReadable($usualSpawnInterval).
						" ({$spawnChance}% chance)";
				} else {
					$spawnTimeMessage .= ". Can spawn every " . $this->util->unixtimeToReadable($usualSpawnInterval);
				}
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
				return "{$mobName}{$spawnTimeMessage}{$killTimeMessage}.";
			}
			$msg = "{$mobName}{$spawnTimeMessage}{$killTimeMessage}.";
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
			return "There is currently no timer for <highlight>{$mobName}<end>.";
		}
		$this->reloadWorldBossTimers();
		return "The timer for <highlight>{$mobName}<end> has been deleted.";
	}

	public function worldBossUpdate(Character $sender, string $mobName, int $vulnerable): bool {
		/** @phpstan-var null|array{"interval":int, "immortal":int} */
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
					"timer" => $mobData[static::INTERVAL] ?? null,
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

	/** Show all upcoming world boss spawn timers in the correct order */
	#[NCA\HandlesCommand("wb")]
	#[NCA\Help\Group("worldboss")]
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

	/** Show the next spawn time(s) for a world boss */
	#[
		NCA\HandlesCommand("tara"),
		NCA\HandlesCommand("loren"),
		NCA\HandlesCommand("reaper"),
		NCA\HandlesCommand("gauntlet"),
		NCA\HandlesCommand("father"),
		NCA\Help\Group("worldboss")
	]
	public function bossSpawnCommand(CmdContext $context): void {
		$context->reply($this->getWorldBossMessage($this->getMobFromContext($context)));
	}

	/** Mark a worldboss as killed, setting a new timer for it */
	#[
		NCA\HandlesCommand(self::CMD_TARA_UPDATE),
		NCA\HandlesCommand(self::CMD_LOREN_UPDATE),
		NCA\HandlesCommand(self::CMD_REAPER_UPDATE),
		NCA\HandlesCommand(self::CMD_GAUNTLET_UPDATE),
		NCA\HandlesCommand(self::CMD_FATHER_UPDATE),
		NCA\Help\Group("worldboss")
	]
	public function bossKillCommand(CmdContext $context, #[NCA\Str("kill")] string $action): void {
		$boss = $this->getMobFromContext($context);
		$this->worldBossUpdate($context->char, $boss, 0);
		$msg = "The timer for <highlight>{$boss}<end> has been updated.";
		$context->reply($msg);
		$msg = "<highlight>{$boss}<end> has been killed.";
		$this->announceBigBossEvent($boss, $msg, 3);
		$this->sendSyncEvent($context->char->name, $boss, 0, $context->forceSync);
	}

	/** Manually update a worldboss's timer by giving the time until it is vulnerable */
	#[
		NCA\HandlesCommand(self::CMD_TARA_UPDATE),
		NCA\HandlesCommand(self::CMD_LOREN_UPDATE),
		NCA\HandlesCommand(self::CMD_REAPER_UPDATE),
		NCA\HandlesCommand(self::CMD_GAUNTLET_UPDATE),
		NCA\HandlesCommand(self::CMD_FATHER_UPDATE),
		NCA\Help\Group("worldboss")
	]
	public function bossUpdateCommand(
		CmdContext $context,
		#[NCA\Str("update")] string $action,
		PDuration $durationUntilVulnerable
	): void {
		$boss = $this->getMobFromContext($context);
		$this->worldBossUpdate($context->char, $boss, $durationUntilVulnerable->toSecs());
		$msg = "The timer for <highlight>{$boss}<end> has been updated.";
		$context->reply($msg);
		$this->sendSyncEvent($context->char->name, $boss, $durationUntilVulnerable->toSecs(), $context->forceSync);
	}

	/** Completely remove a worldboss's timer, because you are not interested in it */
	#[
		NCA\HandlesCommand(self::CMD_TARA_UPDATE),
		NCA\HandlesCommand(self::CMD_LOREN_UPDATE),
		NCA\HandlesCommand(self::CMD_REAPER_UPDATE),
		NCA\HandlesCommand(self::CMD_FATHER_UPDATE),
		NCA\Help\Group("worldboss")
	]
	public function bossDeleteCommand(CmdContext $context, PRemove $action): void {
		$boss = $this->getMobFromContext($context);
		$msg = $this->worldBossDeleteCommand($context->char, $boss);
		$context->reply($msg);
		$this->sendSyncDeleteEvent($context->char->name, $boss, $context->forceSync);
	}

	#[NCA\Event(
		name: "timer(1sec)",
		description: "Check timer to announce big boss events"
	)]
	public function checkTimerEvent(Event $eventObj, int $interval, bool $manual=false): void {
		$timers = $this->getWorldBossTimers();
		$triggered = false;
		$showSpawn = $this->worldbossShowSpawn;
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
							"<end>";
					}

					/** @phpstan-var null|array{int,int,int} */
					$coords = static::BOSS_DATA[$timer->mob_name][static::COORDS] ?? null;
					if (isset($coords)) {
						$pf = $this->pfController->getPlayfieldById($coords[2]);
						if (isset($pf)) {
							$wpLink = $this->text->makeChatcmd(
								$pf->long_name,
								"/waypoint {$coords[0]} {$coords[1]} {$coords[2]}"
							);
							$blob = $timer->mob_name . " is in [{$wpLink}]";
							$aou = self::BOSS_DATA[$timer->mob_name][self::AOU] ?? null;
							if (isset($aou)) {
								$blob .= "\nMore info: [".
									$this->text->makeChatcmd("guide", "/tell <myname> aou {$aou}").
									"] [".
									$this->text->makeChatcmd("see AO-Universe", "/start https://www.ao-universe.com/guides/{$aou}").
									"]";
							}
							$popup = ((array)$this->text->makeBlob(
								"waypoint",
								$blob,
								"Waypoint for {$timer->mob_name}",
							))[0];
							$msg .= " [{$popup}]";
						}
					} else {
						$msg .= ".";
					}
				}
				$this->announceBigBossEvent($timer->mob_name, $msg, 2);
				$triggered = true;
			}
			$nextKillTime = null;
			if (isset($timer->timer)) {
				$nextKillTime = time() + $timer->timer + $invulnerableTime;
			}
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
		$this->timers = $this->addNextDates($this->timers);
	}

	#[NCA\Event(
		name: "sync(worldboss)",
		description: "Sync external worldboss timers"
	)]
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

	#[NCA\Event(
		name: "sync(worldboss-delete)",
		description: "Sync external worldboss timer deletes"
	)]
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

	#[
		NCA\NewsTile(
			name: "boss-timers",
			description: "A list of upcoming boss spawn timers",
			example: "<header2>Boss timers<end>\n".
				"<tab>The Hollow Reaper spawns in <highlight>8 hrs 18 mins 26 secs<end>.\n".
				"<tab>Tarasque spawns in <highlight>8 hrs 1 min 48 secs<end>.\n".
				"<tab>The Gauntlet portal will be open for <highlight>5 mins 9 secs<end>."
		)
	]
	public function bossTimersNewsTile(string $sender, callable $callback): void {
		$timers = $this->getWorldBossTimers();
		if (!count($timers)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Boss timers<end>";
		foreach ($timers as $timer) {
			if (isset($timer->timer)) {
				$blob .= "\n<tab>" . $this->formatWorldBossMessage($timer, true, true);
			}
		}
		$callback($blob);
	}

	#[
		NCA\NewsTile(
			name: "all-boss-timers",
			description: "A list of all boss spawn timers",
			example: "<header2>Boss timers<end>\n".
				"<tab>Zaal The Immortal last spawn <grey>7h 13m ago<end>. Spawns every 6 hrs (75% chance).\n".
				"<tab>Tarasque spawns in <highlight>8 hrs 1 min 48 secs<end>.\n".
				"<tab>The Gauntlet portal will be open for <highlight>5 mins 9 secs<end>."
		)
	]
	public function allBossTimersNewsTile(string $sender, callable $callback): void {
		$timers = $this->getWorldBossTimers();
		if (!count($timers)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Boss timers<end>";
		foreach ($timers as $timer) {
			$blob .= "\n<tab>" . $this->formatWorldBossMessage($timer, true, true);
		}
		$callback($blob);
	}

	#[
		NCA\NewsTile(
			name: "tara-timer",
			description: "The current tara timer",
			example: "<header2>Tara timer<end>\n".
				"<tab>Tarasque spawns in <highlight>8 hrs 1 min 48 secs<end>."
		)
	]
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

	#[
		NCA\NewsTile(
			name: "gauntlet-timer",
			description: "Show when Vizaresh spawns/is vulnerable",
			example: "<header2>Gauntlet<end>\n".
				"<tab>The Gauntlet portal will be open for <highlight>5 mins 9 secs<end>."
		)
	]
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

	#[
		NCA\NewsTile(
			name: "gauntlet",
			description: "Show when Vizaresh spawns/is vulnerable",
			example: "<header2>Gauntlet<end>\n".
				"<tab>The Gauntlet portal will be open for <highlight>5 mins 9 secs<end>.\n".
				"<tab><omni>Omni Gauntlet buff<end> runs out in <highlight>4 hrs 59 mins 31 secs<end>."
		)
	]
	public function gauntletNewsTile(string $sender, callable $callback): void {
		$timer = $this->getWorldBossTimer(static::VIZARESH);
		$buffLine = $this->gauntletBuffController->getGauntletBuffLine();
		if (!isset($timer) && !isset($buffLine)) {
			$callback(null);
			return;
		}
		$blob = "<header2>Gauntlet<end>";
		if (isset($timer)) {
			$blob .= "\n<tab>" . $this->formatWorldBossMessage($timer, true, true);
		}
		if (isset($buffLine)) {
			$blob .= "\n{$buffLine}";
		}
		$callback($blob);
	}

	/**
	 * Check if the given timer is more accurate than our own stored
	 * information, and if so, update our database and timers.
	 */
	protected function handleApiTimer(ApiSpawnData $timer): bool {
		$this->logger->info("Received timer information for {name} on RK{dimension}.", [
			"name" => $timer->name,
			"dimension" => $timer->dimension,
		]);
		if ($timer->dimension !== $this->config->dimension) {
			return false;
		}
		$map = array_flip(static::BOSS_MAP);
		$map["gauntlet"] = $map["vizaresh"];
		$mobName = $map[$timer->name] ?? null;
		if (!isset($mobName)) {
			$this->logger->warning("Received timer information for unknown boss {$timer->name}.");
			return false;
		}
		$ourTimer = $this->getWorldBossTimer($mobName);
		$apiTimer = $this->apiTimerToWorldbossTimer($timer, $mobName);
		if (isset($ourTimer) && $apiTimer->next_spawn <= $ourTimer->next_spawn) {
			return false;
		}
		$this->logger->info("Updating {$mobName} timer from API");
		$this->worldBossUpdate(
			new Character("Timer-API"),
			$mobName,
			($apiTimer->next_killable??time()) - time()
		);
		return true;
	}

	/** Convert timer information from the API into an actual timer with correct information */
	protected function apiTimerToWorldbossTimer(ApiSpawnData $timer, string $mobName): WorldBossTimer {
		$newTimer = new WorldBossTimer();
		$newTimer->spawn = $timer->last_spawn;
		$newTimer->killable = $timer->last_spawn + static::BOSS_DATA[$mobName][static::IMMORTAL];
		$newTimer->next_killable = $newTimer->killable;
		$newTimer->mob_name = $mobName;

		/** @var ?int */
		$interval = static::BOSS_DATA[$mobName][static::INTERVAL] ?? null;
		$newTimer->timer = $interval;
		$this->addNextDates([$newTimer]);
		return $newTimer;
	}

	/**
	 * @param WorldBossTimer[] $timers
	 *
	 * @return WorldBossTimer[]
	 */
	protected function addNextDates(array $timers): array {
		$showSpawn = $this->worldbossShowSpawn;
		$newTimers = [];
		foreach ($timers as $timer) {
			$invulnerableTime = $timer->killable - $timer->spawn;
			$timer->next_killable = $timer->killable;
			$timer->next_spawn    = $timer->spawn;
			if ($showSpawn === static::SPAWN_EVENT && !$this->lastSpawnPrecise[$timer->mob_name]) {
				$newTimers []= $timer;
				continue;
			}
			if ($timer->next_killable <= time() && !isset($timer->timer)) {
				if (time() - $timer->next_killable <= 48 *3600) {
					$newTimers []= $timer;
				}
				continue;
			}
			if (isset($timer->timer)) {
				while ($timer->next_killable <= time()) {
					$timer->next_killable += $timer->timer + $invulnerableTime;
					$timer->next_spawn    += $timer->timer + $invulnerableTime;
				}
			}
			$newTimers []= $timer;
		}
		usort($newTimers, function (WorldBossTimer $a, WorldBossTimer $b) {
			return $a->next_spawn <=> $b->next_spawn;
		});
		return $newTimers;
	}

	/** @return WorldBossTimer[] */
	protected function getWorldBossTimers(): array {
		return $this->timers;
	}

	protected function reloadWorldBossTimers(): void {
		/** @var WorldBossTimer[] */
		$timers = $this->db->table(static::DB_TABLE)
			->asObj(WorldBossTimer::class)
			->toArray();
		$this->timers = $this->addNextDates($timers);
	}

	protected function niceTime(int $timestamp): string {
		$time = new DateTime();
		$time->setTimestamp($timestamp);
		return $time->format("D, H:i T (d-M-Y)");
	}

	protected function getNextSpawnsMessage(WorldBossTimer $timer, int $howMany=10): string {
		if (!isset($timer->timer)) {
			return "";
		}
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

	protected function getMobFromContext(CmdContext $context): string {
		$mobs = array_flip(static::BOSS_MAP);
		$mobs["gauntlet"] = $mobs["vizaresh"];
		$mobs["father"] = $mobs["father-time"];
		return $mobs[explode(" ", strtolower($context->message))[0]];
	}

	/**
	 * Announce an event
	 *
	 * @param string $msg  The message to send
	 * @param int    $step 1 => spawns soon, 2 => has spawned, 3 => vulnerable
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
		$bossName = static::BOSS_MAP[$boss] ?? null;
		if ($bossName === 'vizaresh') {
			$bossName = 'gauntlet';
		} elseif ($bossName === null) {
			return;
		}
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(
			"spawn",
			"{$bossName}-{$event}",
			join("-", array_map("ucfirst", explode("-", static::BOSS_MAP[$boss])))
		));
		$this->messageHub->handle($rMsg);
	}

	/**
	 * Parse the incoming data and call the function to handle the
	 * timers if the data is valid.
	 *
	 * @return int Number if updated timers
	 */
	private function handleTimerData(string $body): int {
		if (!strlen($body)) {
			$this->logger->error('Worldboss API sent an empty reply');
			return 0;
		}

		/** @var ApiSpawnData[] */
		$timers = [];
		try {
			$data = json_decode($body, true);
			if (!is_array($data)) {
				throw new JsonException();
			}
			foreach ($data as $timerData) {
				$timers []= new ApiSpawnData($timerData);
			}
		} catch (JsonException) {
			$this->logger->error("Worldboss API sent invalid json.", [
				"json" => $body,
			]);
			return 0;
		}
		$numUpdates = 0;
		foreach ($timers as $timer) {
			if ($this->handleApiTimer($timer)) {
				$numUpdates++;
			}
		}
		return $numUpdates;
	}
}
