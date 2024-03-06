<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use function Amp\delay;
use function Safe\{json_decode};
use Amp\Http\Client\{HttpClientBuilder, Request};
use DateTime;
use DateTimeZone;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	Config\BotConfig,
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
use Nadybot\Modules\HELPBOT_MODULE\{PlayfieldController};
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
	NCA\DefineCommand(
		command: "wbdebug",
		accessLevel: "mod",
		description: "Show low-level information about WorldBoss-timers",
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
	public BotConfig $config;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public GauntletBuffController $gauntletBuffController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	public HttpClientBuilder $http;

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

	/** Message to send to inform about upcoming spawn */
	#[NCA\Setting\Template(
		options: [
			"{c-mob-name} will spawn in {c-next-spawn}.",
			"{c-mob-name} will spawn in {c-next-spawn}{?immortal: and stay immortal for {c-immortal}}.",
		],
		help: 'will_spawn_text.txt',
		exampleValues: [
			"c-mob-name" => "<highlight>Tarasque<end>",
			"mob-name" => "Tarasque",
			"c-next-spawn" => "<highlight>15 minutes<end>",
			"next-spawn" => "15 minutes",
			"immortal" => "30 minutes",
			"c-immortal" => "<highlight>30 minutes<end>",
		],
	)]
	public string $willSpawnText = "{c-mob-name} will spawn in {c-next-spawn}.";

	/** Message to send to inform about a spawn that should be any time now */
	#[NCA\Setting\Template(
		options: [
			"{c-mob-name} should spawn any time now.",
			"{c-mob-name} should spawn any time now{?immortal: and will be immortal for {c-immortal}}.",
		],
		help: 'should_spawn_text.txt',
		exampleValues: [
			"c-mob-name" => "<highlight>Tarasque<end>",
			"mob-name" => "Tarasque",
			"c-immortal" => "<highlight>30 minutes<end>",
			"immortal" => "30 minutes",
		],
	)]
	public string $shouldSpawnText = "{c-mob-name} should spawn any time now".
		"{?immortal: and will be immortal for {c-immortal}}.";

	/** Message to send to inform about a spawn that has happened right now */
	#[NCA\Setting\Template(
		options: [
			"{c-mob-name} has spawned.",
			"{c-mob-name} has spawned{?immortal: and will be vulnerable in {c-immortal}}.",
		],
		help: 'has_spawned_text.txt',
		exampleValues: [
			"c-mob-name" => "<highlight>Tarasque<end>",
			"mob-name" => "Tarasque",
			"c-immortal" => "<highlight>30 minutes<end>",
			"immortal" => "30 minutes",
		],
	)]
	public string $hasSpawnedText = "{c-mob-name} has spawned".
		"{?immortal: and will be vulnerable in {c-immortal}}.";

	/** Message to send if a worldboss should no longer be immortal */
	#[NCA\Setting\Template(
		options: [
			"{c-mob-name} should no longer be immortal.",
		],
		help: 'should_vulnerable_text.txt',
		exampleValues: [
			"c-mob-name" => "<highlight>Tarasque<end>",
			"mob-name" => "Tarasque",
		],
	)]
	public string $shouldVulnerableText = "{c-mob-name} should no longer be immortal.";

	/** Message to send if a worldboss is no longer immortal */
	#[NCA\Setting\Template(
		options: [
			"{c-mob-name} is no longer immortal.",
		],
		help: 'is_vulnerable_text.txt',
		exampleValues: [
			"c-mob-name" => "<highlight>Tarasque<end>",
			"mob-name" => "Tarasque",
		],
	)]
	public string $isVulnerableText = "{c-mob-name} is no longer immortal.";

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

	// Last time the 1s timer ran
	private int $lastCheck = 0;

	public function __construct() {
		$this->lastCheck = time();
	}

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

	/** Debug worldboss-timers */
	#[NCA\HandlesCommand("wbdebug")]
	public function debugWbCommand(CmdContext $context): void {
		$timers = $this->getWorldBossTimers();
		$blocks = [];
		foreach ($timers as $timer) {
			// [$timer] = $this->addNextDates([clone $timer]);
			$blocks[] = "<header2>{$timer->mob_name}<end>".
				(
					isset($timer->timer)
					? "\n<tab>Spawn timer: <highlight>". $this->util->unixtimeToReadable($timer->timer) . "<end>"
					: ""
				).
				"\n<tab>Last Spawn: <highlight>". $this->util->date($timer->spawn) . "<end>".
				(
					(!isset($timer->next_spawn) || ($timer->spawn === $timer->next_spawn))
					? ""
					: "\n<tab>Next Spawn: <highlight>". $this->util->date($timer->next_spawn) . "<end>"
				).
				"\n<tab>Last Vulnerable: <highlight>". $this->util->date($timer->killable) . "<end>".
				(
					(!isset($timer->next_killable) || ($timer->killable === $timer->next_killable))
					? ""
					: "\n<tab>Next Vulnerable: <highlight>". $this->util->date($timer->next_killable) . "<end>"
				).
				"\n<tab>Time Submitted: <highlight>". $this->util->date($timer->time_submitted) . "<end>".
				"\n<tab>Submitter: <highlight>". $timer->submitter_name . "<end>".
				"\n<tab>Precise: " . (($this->lastSpawnPrecise[$timer->mob_name]??false) ? "<green>yes<end>" : "<red>no<end>");
		}
		$msg = $this->text->makeBlob("Worldboss timings", join("\n\n", $blocks));
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("updatewb")]
	public function updateWbCommand(CmdContext $context): void {
		try {
			$numUpdates = $this->loadTimersFromAPI();
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
	public function loadTimersFromAPI(): int {
		$client = $this->builder->build();

		try {
			$response = $client->request(new Request(static::WORLDBOSS_API));
			$code = $response->getStatus();
			if ($code >= 500 && $code < 600 && --$this->timerRetriesLeft) {
				$this->logger->warning('Worldboss API sent a {code}, retrying in 5s', [
					"code" => $code,
				]);
				delay(5);
				return $this->loadTimersFromAPI();
			}
			if ($code !== 200) {
				$this->logger->error('Worldboss API replied with error {code} ({reason})', [
					"code" => $code,
					"reason" => $response->getReason(),
					"headers" => $response->getHeaderPairs(),
				]);
				return 0;
			}

			$body = $response->getBody()->buffer();
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
		$data = [
			"mob_name" => $mobName,
			"timer" => $mobData[static::INTERVAL] ?? null,
			"spawn" => $vulnerable - $mobData[static::IMMORTAL],
			"killable" => $vulnerable,
			"time_submitted" => time(),
			"submitter_name" => $sender->name,
		];
		$this->logger->notice("Update for {mob_name} stored in DB", [
			"mob_name" => $mobName,
			"data" => $data,
		]);
		$this->db->table(static::DB_TABLE)->upsert($data, ["mob_name"]);
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
		for ($i = 0; $i < count($timers); $i++) {
			$timers[$i] = clone $timers[$i];
		}
		$this->addNextDates($timers);
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
		#[NCA\Str("update")]
		string $action,
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
		$lastCheck = $this->lastCheck;
		$this->lastCheck = time();
		$timers = $this->getWorldBossTimers();
		$triggered = false;
		foreach ($timers as $timer) {
			$newTriggered = $this->checkTimer($timer, $lastCheck, $manual);
			$triggered = $triggered || $newTriggered;
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
			$this->logger->warning("Received timer update for unknown boss {boss}.", [
				"boss" => $event->boss,
			]);
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
			$this->logger->warning("Received timer update for unknown boss {boss}.", [
				"boss" => $event->boss,
			]);
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
		if ($timer->dimension !== $this->config->main->dimension) {
			return false;
		}
		$map = array_flip(static::BOSS_MAP);
		$map["gauntlet"] = $map["vizaresh"];
		$mobName = $map[$timer->name] ?? null;
		if (!isset($mobName) || !is_string($mobName)) {
			$this->logger->warning("Received timer information for unknown boss {boss}.", [
				"boss" => $timer->name,
			]);
			return false;
		}
		$ourTimer = $this->getWorldBossTimer($mobName);
		$apiTimer = $this->apiTimerToWorldbossTimer($timer, $mobName);
		if (isset($ourTimer) && $apiTimer->next_spawn <= $ourTimer->next_spawn) {
			if (in_array($ourTimer->submitter_name, ['Timer-API', 'Nadybot', '_Nadybot'])) {
				$this->lastSpawnPrecise[$mobName] = true;
			}
			return false;
		}
		$this->logger->info("Updating {boss} timer from API", ["boss" => $mobName]);
		$this->worldBossUpdate(
			new Character("Timer-API"),
			$mobName,
			($apiTimer->next_killable??time()) - time()
		);
		$this->lastSpawnPrecise[$mobName] = true;
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

	/** Does the mob spawn in exactly 15 minutes from now? */
	private function isPrespawn(WorldBossTimer $timer, int $lastCheck, bool $manual): bool {
		return $timer->next_spawn > $lastCheck+15*60 &&
			$timer->next_spawn <= time()+15*60;
	}

	/** Did the mob spawn just now? */
	private function isSpawn(WorldBossTimer $timer, int $lastCheck, bool $manual): bool {
		return (
			$timer->next_spawn > $lastCheck &&
			$timer->next_spawn <= time()
		) || (
			$manual &&
			isset($timer->next_spawn) &&
			$timer->next_spawn <= time() &&
			time() - $timer->next_spawn < 10
		);
	}

	/** Did the mob become vulnerable just now? */
	private function isVulnerable(WorldBossTimer $timer, int $lastCheck, bool $manual): bool {
		$nextKillTime = null;
		$invulnerableTime = $timer->killable - $timer->spawn;
		if (isset($timer->timer) && isset($timer->next_spawn)) {
			$nextKillTime = $timer->next_spawn + $timer->timer + $invulnerableTime;
		}
		return (
			$timer->next_killable > $lastCheck &&
			$timer->next_killable <= time()
		) || (
			$timer->next_killable === $nextKillTime
		);
	}

	/** Check and trigger a single timer */
	private function checkTimer(WorldBossTimer $timer, int $lastCheck, bool $manual): bool {
		$showSpawn = $this->worldbossShowSpawn;
		$tokens = [
			"mob-name" => $timer->mob_name,
			"c-mob-name" => "<highlight>{$timer->mob_name}<end>",
		];
		$invulnDuration = static::BOSS_DATA[$timer->mob_name][static::IMMORTAL];
		if (isset($invulnDuration)) {
			$tokens["immortal"] = $this->util->unixtimeToReadable($invulnDuration);
			$tokens["c-immortal"] = "<highlight>" . $tokens["immortal"] . "<end>";
		}
		if ($this->isPrespawn($timer, $lastCheck, $manual)) {
			assert(isset($timer->next_spawn));
			$this->logger->notice("{boss} pre-spawn check success", ["boss" => $timer->mob_name]);
			$tokens["next-spawn"] = $this->util->unixtimeToReadable($timer->next_spawn-time());
			$tokens["c-next-spawn"] = "<highlight>" . $tokens["next-spawn"] . "<end>";
			$msg = $this->text->renderPlaceholders($this->willSpawnText, $tokens);
			$this->announceBigBossEvent($timer->mob_name, $msg, 1);
			return true;
		}
		if ($this->isSpawn($timer, $lastCheck, $manual)) {
			$this->logger->info("{mob_name} spawn check success, manual: {manual}", [
				"mob_name" => $timer->mob_name,
				"manual" => $manual ? "true" : "false",
				"timer" => (array)$timer,
			]);
			$this->lastSpawnPrecise[$timer->mob_name] = $manual;
			if ($showSpawn === static::SPAWN_EVENT && !$manual) {
				$this->logger->info(
					"SPAWN_EVENT for spawn skipped, not manual",
					["timer" => (array)$timer],
				);
				return false;
			} elseif ($showSpawn === static::SPAWN_SHOULD && !$manual) {
				$msg = $this->text->renderPlaceholders($this->shouldSpawnText, $tokens);
			} else {
				if (isset($timer->next_killable) && $timer->next_killable > time()) {
					$tokens["immortal"] = $this->util->unixtimeToReadable($timer->next_killable-time());
					$tokens["c-immortal"] = "<highlight>" . $tokens["immortal"] . "<end>";
				}
				$msg = $this->text->renderPlaceholders($this->hasSpawnedText, $tokens);

				$msg .= $this->getBossWP($timer);
			}
			$this->announceBigBossEvent($timer->mob_name, $msg, 2);
			return true;
		}
		if ($this->isVulnerable($timer, $lastCheck, $manual)) {
			$this->logger->info(
				"{mob_name} killable check success. Manual: {manual}",
				[
					"mob_name" => $timer->mob_name,
					"manual" => $manual ? "true" : "false",
					"timer" => (array)$timer,
				]
			);
			// With this setting, we only want to show "is mortal" when we are 100% sure
			if ($showSpawn === static::SPAWN_EVENT && !$this->lastSpawnPrecise[$timer->mob_name]) {
				$this->logger->info("SPAWN_EVENT for vulnerable skipped, not manual", ["timer" => (array)$timer]);
				return false;
			} elseif ($showSpawn === static::SPAWN_SHOULD && !$this->lastSpawnPrecise[$timer->mob_name]) {
				$msg = $this->text->renderPlaceholders($this->shouldVulnerableText, $tokens);
			} else {
				$msg = $this->text->renderPlaceholders($this->isVulnerableText, $tokens);
			}
			$this->announceBigBossEvent($timer->mob_name, $msg, 3);
			return true;
		}
		return false;
	}

	private function getBossWP(WorldBossTimer $timer): string {
		/** @phpstan-var null|array{int,int,int} */
		$coords = static::BOSS_DATA[$timer->mob_name][static::COORDS] ?? null;
		if (!isset($coords)) {
			return ".";
		}
		$pf = $this->pfController->getPlayfieldById($coords[2]);
		if (!isset($pf)) {
			return "";
		}
		$msg = "";
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
		return $msg;
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
				$timers []= new ApiSpawnData(...$timerData);
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
