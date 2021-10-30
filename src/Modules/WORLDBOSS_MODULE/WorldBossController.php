<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use DateTime;
use Nadybot\Core\{
	CmdContext,
	CommandAlias,
	DB,
	Event,
	EventManager,
	MessageHub,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PDuration;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'boss',
 *		accessLevel = 'all',
 *		description = 'Show next spawntime(s)',
 *		help        = 'boss.txt'
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
 *	@ProvidesEvent("sync(worldboss)")
 */
class WorldBossController {
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
	public Util $util;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public GauntletBuffController $gauntletBuffController;

	/** @Inject */
	public MessageHub $messageHub;

	public const DB_TABLE = "worldboss_timers_<myname>";

	public const INTERVAL = "interval";
	public const IMMORTAL = "immortal";

	public const TARA = 'Tarasque';
	public const REAPER = 'The Hollow Reaper';
	public const LOREN = 'Loren Warr';
	public const VIZARESH = 'Vizaresh';

	public const BOSS_MAP = [
		self::TARA => "tara",
		self::REAPER => "reaper",
		self::LOREN => "loren",
		self::VIZARESH => "gauntlet",
	];

	public const BOSS_DATA = [
		"tara" => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 30*60,
		],
		"reaper" => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
		],
		"loren" => [
			self::INTERVAL => 9*3600,
			self::IMMORTAL => 15*60,
		],
		"gauntlet" => [
			self::INTERVAL => 17*3600,
			self::IMMORTAL => 420,
		],
	];

	/**
	 * @var WorldBossTimer[]
	 */
	public array $timers = [];

	/** @Setup */
	public function setup() {
		$this->db->loadMigrations($this->moduleName, __DIR__ . '/Migrations');
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
		foreach (["tara", "lauren", "reaper", "gauntlet"] as $boss) {
			foreach (["prespawn", "spawn", "vulnerable"] as $event) {
				$emitter = new WorldBossChannel("{$boss}-{$event}");
				$this->messageHub->registerMessageEmitter($emitter);
			}
		}
		$this->reloadWorldBossTimers();
	}

	/**
	 * @param WorldBossTimer[] $timers
	 */
	protected function addNextDates(array $timers): void {
		foreach ($timers as $timer) {
			$invulnerableTime = $timer->killable - $timer->spawn;
			$timer->next_killable = $timer->killable;
			$timer->next_spawn    = $timer->spawn;
			while ($timer->next_killable < time()) {
				$timer->next_killable += $timer->timer + $invulnerableTime;
				$timer->next_spawn    += $timer->timer + $invulnerableTime;
			}
		}
		usort($timers, function($a, $b) {
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
		for ($i = 0; $i < $howMany; $i++) {
			$spawnTime = $timer->next_spawn + $i*$multiplicator;
			$times[] = $this->niceTime($spawnTime);
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
			$msg = "I currently don't have an accurate timer for <highlight>$mobName<end>.";
			return $msg;
		}
		return $this->formatWorldBossMessage($timer, false);
	}

	public function formatWorldBossMessage(WorldBossTimer $timer, bool $short=true): string {
		$nextSpawnsMessage = $this->getNextSpawnsMessage($timer);
		$spawntimes = $this->text->makeBlob("Spawntimes for {$timer->mob_name}", $nextSpawnsMessage);
		$spawnTimeMessage = '';
		if (time() < $timer->next_spawn) {
			if ($timer->mob_name === static::VIZARESH) {
				$secsDead = time() - ($timer->next_spawn - 61200);
				if ($secsDead < 6*60 + 30) {
					$portalOpen = 6*60 + 30 - $secsDead;
					$portalOpenTime = $this->util->unixtimeToReadable($portalOpen);
					$msg = "The Gauntlet portal will be open for <highlight>{$portalOpenTime}<end>.";
					if (!$short) {
						$msg .= " {$spawntimes}";
					}
					return $msg;
				}
			}
			$timeUntilSpawn = $this->util->unixtimeToReadable($timer->next_spawn-time());
			$spawnTimeMessage = " spawns in <highlight>$timeUntilSpawn<end>";
			if ($short) {
				return "{$timer->mob_name}{$spawnTimeMessage}.";
			}
			$spawnTimeMessage .= " and";
		} else {
			$spawnTimeMessage = " spawned and";
		}
		$timeUntilKill = $this->util->unixtimeToReadable($timer->next_killable-time());
		$killTimeMessage = " will be vulnerable in <highlight>$timeUntilKill<end>";
		if ($short) {
			return "{$timer->mob_name}{$spawnTimeMessage}{$killTimeMessage}.";
		}
		$msg = "{$timer->mob_name}${spawnTimeMessage}${killTimeMessage}. $spawntimes";
		return $msg;
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

	public function worldBossKillCommand(Character $sender, string $mobName, int $timeUntilSpawn, int $timeUntilKillable, bool $forceSync=false): string {
		$this->db->table(static::DB_TABLE)
			->upsert(
				[
					"mob_name" => $mobName,
					"timer" => $timeUntilSpawn,
					"spawn" => time() + $timeUntilSpawn,
					"killable" => time() + $timeUntilKillable,
					"time_submitted" => time(),
					"submitter_name" => $sender->name,
				],
				["mob_name"]
			);
		$msg = "The timer for <highlight>$mobName<end> has been updated.";
		$this->reloadWorldBossTimers();
		$event = new SyncWorldbossEvent();
		$event->boss = static::BOSS_MAP[$mobName];
		$event->vulnerable = time() + $timeUntilKillable;
		$event->sender = $sender->name;
		$event->forceSync = $forceSync;
		$this->eventManager->fireEvent($event);
		return $msg;
	}

	public function worldBossUpdateCommand(Character $sender, int $newKillTime, string $mobName, int $downTime, int $timeUntilKillable, bool $forceSync=false): string {
		if ($newKillTime < 1) {
			$msg = "You must enter a valid time parameter for the time until <highlight>${mobName}<end> will be vulnerable.";
			return $msg;
		}
		$newKillTime += time();
		$this->db->table(static::DB_TABLE)
			->upsert(
				[
					"mob_name" => $mobName,
					"timer" => $downTime,
					"spawn" => $newKillTime-$timeUntilKillable,
					"killable" => $newKillTime,
					"time_submitted" => time(),
					"submitter_name" => $sender->name,
				],
				["mob_name"]
			);
		$msg = "The timer for <highlight>$mobName<end> has been updated.";
		$this->reloadWorldBossTimers();
		$event = new SyncWorldbossEvent();
		$event->boss = static::BOSS_MAP[$mobName];
		$event->vulnerable = $newKillTime;
		$event->sender = $sender->name;
		$event->forceSync = $forceSync;
		$this->eventManager->fireEvent($event);
		return $msg;
	}

	/**
	 * @HandlesCommand("boss")
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

	/**
	 * @HandlesCommand("tara")
	 */
	public function taraCommand(CmdContext $context): void {
		$context->reply($this->getWorldBossMessage(static::TARA));
	}

	/**
	 * @HandlesCommand("tara .+")
	 */
	public function taraKillCommand(CmdContext $context, string $action="kill"): void {
		$msg = $this->worldBossKillCommand($context->char, static::TARA, 9*3600, (int)(9.5*3600), $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("tara .+")
	 */
	public function taraUpdateCommand(CmdContext $context, string $action="update", PDuration $duration): void {
		$msg = $this->worldBossUpdateCommand($context->char, $duration->toSecs(), static::TARA, 9*3600, 1800, $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("tara .+")
	 */
	public function taraDeleteCommand(CmdContext $context, PRemove $action): void {
		$msg = $this->worldBossDeleteCommand($context->char, static::TARA);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("reaper")
	 */
	public function reaperCommand(CmdContext $context): void {
		$context->reply($this->getWorldBossMessage(static::REAPER));
	}

	/**
	 * @HandlesCommand("reaper .+")
	 */
	public function reaperKillCommand(CmdContext $context, string $action="kill"): void {
		$msg = $this->worldBossKillCommand($context->char, static::REAPER, 9*3600, (int)(9.25*3600), $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("reaper .+")
	 */
	public function reaperUpdateCommand(CmdContext $context, string $action="update", PDuration $duration): void {
		$msg = $this->worldBossUpdateCommand($context->char, $duration->toSecs(), static::REAPER, 9*3600, 900, $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("reaper .+")
	 */
	public function reaperDeleteCommand(CmdContext $context, PRemove $action): void {
		$msg = $this->worldBossDeleteCommand($context->char, static::REAPER);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("loren")
	 */
	public function lorenCommand(CmdContext $context): void {
		$context->reply($this->getWorldBossMessage(static::LOREN));
	}

	/**
	 * @HandlesCommand("loren .+")
	 */
	public function lorenKillCommand(CmdContext $context, string $action="kill"): void {
		$msg = $this->worldBossKillCommand($context->char, static::LOREN, 9*3600, (int)(9.25*3600), $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("loren .+")
	 */
	public function lorenUpdateCommand(CmdContext $context, string $action="update", PDuration $duration): void {
		$msg = $this->worldBossUpdateCommand($context->char, $duration->toSecs(), static::LOREN, 9*3600, 900, $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("loren .+")
	 */
	public function lorenDeleteCommand(CmdContext $context, PRemove $action): void {
		$msg = $this->worldBossDeleteCommand($context->char, static::LOREN);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("gauntlet")
	 */
	public function gauntletCommand(CmdContext $context): void {
		$context->reply($this->getWorldBossMessage(static::VIZARESH));
	}

	/**
	 * @HandlesCommand("gauntlet .+")
	 */
	public function gauntletKillCommand(CmdContext $context, string $action="kill"): void {
		$msg = $this->worldBossKillCommand($context->char, static::VIZARESH, 61200, 61620, $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("gauntlet .+")
	 */
	public function gauntletUpdateCommand(CmdContext $context, string $action="update", PDuration $duration): void {
		$msg = $this->worldBossUpdateCommand($context->char, $duration->toSecs(), static::VIZARESH, 61200, 420, $context->forceSync);
		$context->reply($msg);
	}

	/**
	 * Announce an event
	 *
	 * @param string $msg The message to send
	 * @param int $step 1 => spawns soon, 2 => has spawned, 3 => vulnerable
	 * @return void
	 */
	protected function announceBigBossEvent(string $boss, string $msg, int $step): void {
		$event = 'spawn';
		if ($step === 1) {
			$event = 'prespawn';
		} elseif ($step === 3) {
			$event = 'vulnerable';
		}
		$rMsg = new RoutableMessage($msg);
		$rMsg->appendPath(new Source(
			"spawn",
			self::BOSS_MAP[$boss] . "-{$event}",
			ucfirst(self::BOSS_MAP[$boss])
		));
		$this->messageHub->handle($rMsg);
	}

	/**
	 * @Event("timer(1sec)")
	 * @Description("Check timer to announce big boss events")
	 */
	public function checkTimerEvent(Event $eventObj): void {
		$timers = $this->getWorldBossTimers();
		$triggered = false;
		foreach ($timers as $timer) {
			$invulnerableTime = $timer->killable - $timer->spawn;
			if ($timer->next_spawn === time()+15*60) {
				$msg = "<highlight>{$timer->mob_name}<end> will spawn in ".
					"<highlight>".$this->util->unixtimeToReadable($timer->next_spawn-time())."<end>.";
				$this->announceBigBossEvent($timer->mob_name, $msg, 1);
				$triggered = true;
			}
			if ($timer->next_spawn === time()) {
				$msg = "<highlight>{$timer->mob_name}<end> has spawned and will be vulnerable in ".
					"<highlight>".$this->util->unixtimeToReadable($timer->next_killable-time())."<end>.";
				$this->announceBigBossEvent($timer->mob_name, $msg, 2);
				$triggered = true;
			}
			$nextKillTime = time() + $timer->timer + $invulnerableTime;
			if ($timer->next_killable === time() || $timer->next_killable === $nextKillTime) {
				$msg = "<highlight>{$timer->mob_name}<end> is no longer immortal.";
				$this->announceBigBossEvent($timer->mob_name, $msg, 3);
				$triggered = true;
			}
		}
		if (!$triggered) {
			return;
		}
		$this->timers = $this->addNextDates($this->timers);
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
		$mobName = $map[$event->boss] ?? null;
		$mobData = static::BOSS_DATA[$event->boss];
		if (!isset($mobName) || !isset($mobData)) {
			// Unknown boss
			return;
		}
		$this->db->table(static::DB_TABLE)
			->upsert(
				[
					"mob_name" => $mobName,
					"timer" => $mobData[static::INTERVAL],
					"spawn" => $event->vulnerable-$mobData[static::IMMORTAL],
					"killable" => $event->vulnerable,
					"time_submitted" => time(),
					"submitter_name" => $event->sender,
				],
				["mob_name"]
			);
		$this->reloadWorldBossTimers();
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
