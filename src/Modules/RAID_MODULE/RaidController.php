<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use DateTime;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	AOChatEvent,
	CommandReply,
	CommandManager,
	DB,
	DBSchema\Player,
	EventManager,
	Modules\ALTS\AltsController,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatAssistController;
use Nadybot\Modules\COMMENT_MODULE\CommentCategory;
use Nadybot\Modules\COMMENT_MODULE\CommentController;
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

/**
 * This class contains all functions necessary to start, stsop and resume a raid
 *
 * @Instance
 * @package Nadybot\Modules\POINT_RAID_MODULE
 *
 * @DefineCommand(
 *     command       = 'raid',
 *     accessLevel   = 'all',
 *     description   = 'Check if the raid is running',
 *     help          = 'raid.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'raid .+',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Everything to run a points raid',
 *     help          = 'raid.txt'
 *
 * )
 * @DefineCommand(
 *     command       = 'raid spp .+',
 *     accessLevel   = 'raid_leader_2',
 *     description   = 'Change the raid points ticker',
 *     help          = 'raid.txt'
 * )
 *
 * @ProvidesEvent("raid(start)")
 * @ProvidesEvent("raid(stop)")
 * @ProvidesEvent("raid(changed)")
 * @ProvidesEvent("raid(lock)")
 * @ProvidesEvent("raid(unlock)")
 */
class RaidController {
	public const DB_TABLE = "raid_<myname>";
	public const DB_TABLE_LOG = "raid_log_<myname>";

	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public RaidMemberController $raidMemberController;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public CommentController $commentController;

	/** @Inject */
	public RaidRankController $raidRankController;

	/** @Inject */
	public OnlineController $onlineController;

	/** @Inject */
	public ChatAssistController $chatAssistController;

	/**
	 * The currently running raid or null if none running
	 */
	public ?Raid $raid = null;

	public const ERR_NO_RAID = "There's currently no raid running.";
	public const CAT_RAID = "raid";

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'raid_announcement',
			'Announce the raid periodically',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'raid_announcement_interval',
			'Announcement interval',
			'edit',
			'time',
			'90s',
			'30s;60s;90s;120s;150s;180s',
			'',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'raid_points_for_time',
			'Give raid points based on duration of participation',
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'raid_points_interval',
			'Point rate, in seconds',
			'edit',
			'time',
			'5m',
			'',
			'',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'raid_auto_add_creator',
			'Add raid initiator to the raid',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'raid_stop_clears_callers',
			'Stopping the raid clears the callers',
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'raid_admin_2'
		);
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Raid");
		$this->timer->callLater(0, [$this, 'resumeRaid']);
	}

	public function getRaidCategory(): CommentCategory {
		$raidCat = $this->commentController->getCategory(static::CAT_RAID);
		if ($raidCat !== null) {
			return $raidCat;
		}
		$raidCat = new CommentCategory();
		$raidCat->name = static::CAT_RAID;
		$raidCat->created_by = $this->chatBot->vars["name"];
		$raidCat->min_al_read = "raid_leader_1";
		$raidCat->min_al_write = "raid_leader_2";
		$raidCat->user_managed = false;
		$this->commentController->saveCategory($raidCat);
		return $raidCat;
	}

	/**
	 * Get the content of the popup that shows you how to join the raid
	 */
	public function getRaidJoinLink(): string {
		$blob = "<header2>Current raid<end>\n".
			"<tab>Description: <highlight>{$this->raid->description}<end>\n".
			"<tab>Duration: running for <highlight>".
			$this->util->unixtimeToReadable(time() - $this->raid->started) . "<end>.\n".
			"<tab>Status: " . ($this->raid->locked ? "<red>locked" : "<green>open") . "<end>\n";
		if ($this->raid->seconds_per_point > 0) {
			$blob .= "<tab>Points: <highlight>1 raid point every ".
				$this->util->unixtimeToReadable($this->raid->seconds_per_point).
				"<end>\n";
		} else {
			$blob .= "<tab>Points: <highlight>Given for each kill by the raid leader(s)<end>\n";
		}
		$blob .= "\n".
			$this->text->makeChatcmd("Join", "/tell <myname> raid join").
			" / ".
			$this->text->makeChatcmd("Leave", "/tell <myname> raid leave").
			" the raid.";
		$blob .= "\n\n".
			$this->text->makeChatcmd("Go LFT", "/lft <myname>");
		return $blob;
	}

	public function getControlInterface(): string {
		$blob = "<header2>Raid Control Interface<end>\n".
			"Raid Status: Running for <highlight>".
			$this->util->unixtimeToReadable(time() - $this->raid->started) . "<end>".
			" [" . $this->text->makeChatcmd("Stop", "/tell <myname> raid stop") . "]\n".
			"Points Status: ";
		if ($this->raid->seconds_per_point > 0) {
			$blob .= "<highlight>1 point every ".
				$this->util->unixtimeToReadable($this->raid->seconds_per_point).
				"<end>\n";
		} else {
			$sppDefault = $this->settingManager->getInt('raid_points_interval');
			$blob .= "<highlight>Given by the raid leader(s)<end>";
			if ($sppDefault > 0) {
				$blob .= " [".
					$this->text->makeChatcmd(
						"Enable ticker",
						"/tell <myname> raid spp {$sppDefault}"
					).
					"]";
			}
			$blob .= "\n";
		}
		$blob .= "Raid State: <highlight>";
		if ($this->raid->locked) {
			$blob .= "locked<end> [".
				$this->text->makeChatcmd("Unlock", "/tell <myname> raid unlock").
				"]\n";
		} else {
			$blob .= "open<end> [".
				$this->text->makeChatcmd("Lock", "/tell <myname> raid lock").
				"]\n";
		}
		$blob .= "Description: <highlight>{$this->raid->description}<end>\n";
		$blob .= "Raid announcement: <highlight>";
		if ($this->raid->announce_interval === 0) {
			$blob .= "off<end> [".
				$this->text->makeChatcmd(
					"Enable",
					"/tell <myname> raid announce ".
					$this->settingManager->getInt('raid_announcement_interval')
				).
				"]\n";
		} else {
			$interval = $this->util->unixtimeToReadable($this->raid->announce_interval);
			$blob .= "every {$interval}<end> [".
				$this->text->makeChatcmd(
					"Disable",
					"/tell <myname> raid announce off"
				).
				"]\n";
		}
		return $blob;
	}

	/**
	 * @HandlesCommand("raid")
	 * @Matches("/^raid$/i")
	 */
	public function raidCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$handler = $this->commandManager->getActiveCommandHandler("raid", "priv", "raid start test");
		$canAdminRaid = $this->accessManager->checkAccess($sender, $handler->admin);
		if ($canAdminRaid) {
			$this->chatBot->sendTell(
				$this->text->makeBlob("Raid Control", $this->getControlInterface()),
				$sender
			);
		}
		$msg = $this->text->makeBlob("click to join", $this->getRaidJoinLink(), "Raid information");
		$sendto->reply($this->raid->getAnnounceMessage($msg));
	}

	/**
	 * Try to resume a raid that was already running when the bot shut down
	 */
	public function resumeRaid(): void {
		/** @var ?Raid */
		$lastRaid = $this->db->table(self::DB_TABLE)
			->orderByDesc("raid_id")
			->limit(1)
			->asObj(Raid::class)
			->first();
		if ($lastRaid === null || $lastRaid->stopped) {
			return;
		}
		/** @var ?RaidLog */
		$lastRaidLog = $this->db->table(self::DB_TABLE_LOG)
			->where("raid_id", $lastRaid->raid_id)
			->orderByDesc("time")
			->limit(1)
			->asObj(RaidLog::class)
			->first();
		if ($lastRaidLog) {
			foreach ($lastRaidLog as $key => $value) {
				if (property_exists($lastRaid, $key)) {
					$lastRaid->{$key} = $value;
				}
			}
		}
		$this->startRaid($lastRaid);
		$this->raidMemberController->resumeRaid($lastRaid);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid (?:start|run|create) (.+)$/i")
	 */
	public function raidStartCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (isset($this->raid)) {
			$sendto->reply("There's already a raid running.");
			return;
		}
		$raid = new Raid();
		$raid->started_by = $sender;
		$raid->description = $args[1];
		if ($this->settingManager->getBool('raid_announcement')) {
			$raid->announce_interval = $this->settingManager->getInt('raid_announcement_interval');
		}
		if ($this->settingManager->getBool('raid_points_for_time')) {
			$raid->seconds_per_point = $this->settingManager->getInt('raid_points_interval');
		}
		$this->startRaid($raid);
		if ($this->settingManager->getBool('raid_auto_add_creator')) {
			$this->raidMemberController->joinRaid($sender, $sender, $channel, false);
		}
		$this->chatBot->sendTell(
			$this->text->makeBlob("Raid Control", $this->getControlInterface()),
			$sender
		);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid (stop|end)$/i")
	 */
	public function raidStopCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$this->stopRaid($sender);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid descr? (.+)$/i")
	 * @Matches("/^raid description (.+)$/i")
	 */
	public function raidChangeDescCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$this->raid->description = $args[1];
		$this->logRaidChanges($this->raid);
		$sendto->reply("Raid description changed.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(change)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("raid spp .+")
	 * @Matches("/^raid spp (\d+)s?$/i")
	 */
	public function raidChangeSppCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$this->raid->seconds_per_point = (int)$args[1];
		$this->logRaidChanges($this->raid);
		$sendto->reply("Raid seconds per point changed.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(change)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid announce(?:ment)? (.+)$/i")
	 */
	public function raidChangeAnnounceCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		if (strtolower($args[1]) === 'off') {
			$this->raid->announce_interval = 0;
			$sendto->reply("Raid announcement turned off.");
		} else {
			$newInterval = $this->util->parseTime($args[1]);
			if ($newInterval === 0) {
				$sendto->reply("<highlight>{$args[1]}<end> is not a valid interval.");
				return;
			}
			$this->raid->announce_interval = $newInterval;
			$sendto->reply("Raid announcement interval changed to <highlight>{$args[1]}<end>.");
		}

		$this->logRaidChanges($this->raid);
		$event = new RaidEvent($this->raid);
		$event->type = "raid(change)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid lock$/i")
	 */
	public function raidLockCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		if ($this->raid->locked) {
			$sendto->reply("The raid is already locked.");
			return;
		}
		$this->raid->locked = true;
		$this->logRaidChanges($this->raid);
		$this->chatBot->sendPrivate("{$sender} <red>locked<end> the raid.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(lock)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid unlock$/i")
	 */
	public function raidUnlockCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		if ($this->raid->locked === false) {
			$sendto->reply("The raid is already unlocked.");
			return;
		}
		$this->raid->locked = false;
		$this->logRaidChanges($this->raid);
		$this->chatBot->sendPrivate("{$sender} <green>unlocked<end> the raid.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(unlock)";
		$event->player = $sender;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid check$/i")
	 */
	public function raidCheckCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$this->raidMemberController->sendRaidCheckBlob($this->raid, $sendto);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid list$/i")
	 */
	public function raidListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$sendto->reply($this->raidMemberController->getRaidListBlob($this->raid));
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid notin$/i")
	 */
	public function raidNotinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$notInRaid = $this->raidMemberController->sendNotInRaidWarning($this->raid);
		if (!count($notInRaid)) {
			$sendto->reply("Everyone is in the raid.");
			return;
		}
		$this->playerManager->massGetByNameAsync(
			function(array $result) use ($sendto) {
				$this->reportNotInResult($result, $sendto);
			},
			$notInRaid
		);
	}

	protected function reportNotInResult(array $players, CommandReply $sendto): void {
		$blob = "<header2>Players that were warned<end>\n";
		ksort($players);
		foreach ($players as $name => $player) {
			if ($player instanceof Player) {
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".
					$this->onlineController->getProfessionId($player->profession).">";
				$blob .= "<tab>{$profIcon} {$player->name} - {$player->level}/{$player->ai_level}\n";
			} else {
				$blob .= "<tab>{$name}\n";
			}
		}
		$s = (count($players) === 1) ? "" : "s";
		$msgs = (array)$this->text->makeBlob(count($players) . " player{$s}", $blob, "Players not in the raid");
		foreach ($msgs as &$msg) {
			$msg = "Sent not in raid warning to $msg.";
		}
		$sendto->reply($msgs);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid history$/i")
	 */
	public function raidHistoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$query = $this->db->table(self::DB_TABLE, "r")
			->join(RaidPointsController::DB_TABLE_LOG . ' AS p', "r.raid_id", "p.raid_id")
			->where("p.individual", false)
			->orWhere("p.ticker", true)
			->groupBy("r.raid_id", "r.started", "r.stopped")
			->orderByDesc("r.raid_id")
			->limit(50)
			->select("r.raid_id", "r.started", "r.stopped");
		$raids = $query->addSelect(
			$query->rawFunc(
				"COUNT",
				$query->colFunc("DISTINCT", "username"),
				"raiders"
			),
			$query->colFunc("SUM", "delta", "points")
		)->asObj();
		if ($raids->isEmpty()) {
			$msg = "No raids have ever been run on <myname>.";
			$sendto->reply($msg);
			return;
		}
		$blob = "";
		foreach ($raids as $raid) {
			$time = DateTime::createFromFormat("U", (string)$raid->started)->format("Y-m-d H:i:s");
			$avgPoints = round($raid->points / $raid->raiders, 1);
			$detailsCmd = $this->text->makeChatcmd(
				"details",
				"/tell <myname> raid history {$raid->raid_id}"
			);
			$blob .= "<highlight>{$time}<end> - {$raid->raiders} raider ".
				"with avg. <highlight>{$avgPoints}<end> points ".
				"[{$detailsCmd}]\n";
		}
		$msg = $this->text->makeBlob("Last Raids (" . count($raids).")", $blob);
		$sendto->reply($msg);
	}

	protected function getRaidSummary(Raid $raid): string {
		$blob  = "<header2>Raid Nr. {$raid->raid_id}<end>\n";
		$blob .= "<tab>Started:  ".
			"<highlight>" . $this->util->date($raid->started) . "<end> ".
			"by <highlight>{$raid->started_by}<end>\n";
		if (isset($raid->stopped)) {
			$blob .= "<tab>Stopped: ".
				"<highlight>" . $this->util->date($raid->stopped) . "<end> ".
				"by <highlight>{$raid->stopped_by}<end>\n";
		}
		$blob .= "<tab>Description: <highlight>{$raid->description}<end>\n";
		$blob .= "<tab>Raid points: ";
		if ($raid->seconds_per_point === 0) {
			$blob .= "<highlight>Given per kill<end>\n";
		} else {
			$blob .= "<highlight>1 point every ".
				$this->util->unixtimeToReadable($raid->seconds_per_point).
				"<end>\n";
		}
		return $blob;
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid history (\d+)$/i")
	 */
	public function raidHistoryDetailCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var ?Raid */
		$raid = $this->db->table(self::DB_TABLE)
			->where("raid_id", (int)$args[1])
			->asObj(Raid::class)->first();
		if ($raid === null) {
			$sendto->reply("The raid <highlight>{$args[1]}<end> doesn't exist.");
			return;
		}
		$query = $this->db->table(RaidPointsController::DB_TABLE_LOG)
			->where("raid_id", (int)$args[1])
			->where("individual", false)
			->groupBy("username")
			->select("username");
		$query->addSelect($query->colFunc("SUM", "delta", "delta"));

		$noPoints = $this->db->table(RaidMemberController::DB_TABLE, "rm")
			->leftJoin(RaidPointsController::DB_TABLE_LOG . " as l", function (JoinClause $join) {
				$join->on("rm.raid_id", "l.raid_id")
					->on("rm.player", "l.username");
			})
			->where("rm.raid_id", (int)$args[1])
			->whereNull("l.username")
			->groupBy("rm.player")
			->select("rm.player AS username");
		$noPoints->selectRaw("0" . $noPoints->as("delta"));

		/** @var Collection<RaidPointsLog> */
		$raiders = $this->db->fromSub($query->union($noPoints), "points")
			->orderBy("username")
			->asObj(RaidPointsLog::class);

		$blob = $this->getRaidSummary($raid);
		$blob .= "\n<header2>Raiders and points<end>\n";
		foreach ($raiders as $raider) {
			$detailsCmd = $this->text->makeChatcmd(
				$raider->username,
				"/tell <myname> raid history {$raid->raid_id} {$raider->username}"
			);
			$main = $this->altsController->getAltInfo($raider->username)->main;
			$blob .= $this->text->alignNumber($raider->delta, 7).
				" - {$detailsCmd}";
			if ($raider->username !== $main) {
				$blob .= " (<highlight>{$main}<end>)";
			}
			$blob .= "\n";
		}
		$msg = $this->text->makeBlob("Raid {$raid->raid_id} details", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid history (\d+) (.+)$/i")
	 */
	public function raidHistoryDetailRaiderCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args[2] = ucfirst(strtolower($args[2]));
		/** @var ?Raid */
		$raid = $this->db->table(self::DB_TABLE)
			->where('raid_id', (int)$args[1])
			->asObj(Raid::class)
			->first();
		if ($raid === null) {
			$sendto->reply("The raid <highlight>{$args[1]}<end> doesn't exist.");
			return;
		}
		/** @var Collection<RaidPointsLog> */
		$logs = $this->db->table(RaidPointsController::DB_TABLE_LOG)
			->where("raid_id", (int)$args[1])
			->where("username", $args[2])
			->asObj(RaidPointsLog::class);
		$joined = $this->db->table(RaidMemberController::DB_TABLE)
			->where("raid_id", (int)$args[1])
			->where("player", $args[2])
			->whereNotNull("joined")
			->select("joined AS time");
		$joined->selectRaw("1" . $joined->as("status"));
		$left = $this->db->table(RaidMemberController::DB_TABLE)
			->where("raid_id", (int)$args[1])
			->where("player", $args[2])
			->whereNotNull("left")
			->select("left AS time");
		$left->selectRaw("0" . $left->as("status"));
		$events = $joined->union($left)->orderBy("time")->asObj();
		$allLogs = $logs->concat($events)
			->sort(function($a, $b) {
				return $a->time <=> $b->time;
			});
		if ($allLogs->isEmpty()) {
			$sendto->reply("<highlight>{$args[2]}<end> didn't get any points in this raid.");
			return;
		}
		$main = $this->altsController->getAltInfo($args[2])->main;
		$blob = $this->getRaidSummary($raid);
		$blob .= "\n<header2>Detailed points for {$args[2]}";
		if ($main !== $args[2]) {
			$blob .= " ({$main})";
		}
		$blob .= "<end>\n";
		$blob .= "<tab>" . $this->util->date($raid->started) . "<tab>".
			"Raid started by {$raid->started_by}\n";
		foreach ($allLogs as $log) {
			if ($log instanceof RaidPointsLog) {
				$blob .= "<tab>" . $this->util->date($log->time) . "<tab>".
					$this->text->alignNumber(abs($log->delta), 5, $log->delta > 0 ? 'green' : 'red').
					" - ".
					($log->individual ? "<highlight>" : "").
					$log->reason.
					($log->individual ? "<end>" : "").
					" (by {$log->changed_by})\n";
			} else {
				$blob .= "<tab>" . $this->util->date($log->time) . "<tab>".
					($log->status ? "Raid joined" : "Raid left") . "\n";
			}
		}
		if (isset($raid->stopped)) {
			$blob .= "<tab>" . $this->util->date($raid->stopped) . "<tab>".
				"Raid stopped by {$raid->stopped_by}\n";
		}
		$msg = $this->text->makeBlob("Raid {$raid->raid_id} details for {$args[2]}", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid dual$/i")
	 */
	public function raidDualCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		/** @var array<string,bool> */
		$mains = [];
		/** @var array<string,array<string,bool>> */
		$duals = [];
		foreach ($this->raid->raiders as $name => $raider) {
			if ($raider->left !== null) {
				continue;
			}
			$altInfo = $this->altsController->getAltInfo($raider->player);
			if (isset($mains[$altInfo->main])) {
				continue;
			}
			$mains[$altInfo->main] = true;
			foreach ($altInfo->getAllValidatedAlts() as $alt) {
				if ($alt === $name) {
					continue;
				}
				if (!isset($this->chatBot->chatlist[$alt])) {
					continue;
				}
				$duals[$name] ??= [];
				$duals[$name][$alt] = isset($this->raid->raiders[$alt])
					&& !isset($this->raid->raiders[$alt]->left);
			}
		}
		if (!count($duals)) {
			$sendto->reply("No one is currently dual-logged.");
			return;
		}
		$toLookup = [];
		foreach ($duals as $name => $alts) {
			$toLookup = [...$toLookup, $name, ...array_keys($alts)];
		}
		$this->playerManager->massGetByNameAsync(
			function (array $lookup) use ($duals, $sendto): void {
				$blob = "";
				foreach ($duals as $name => $alts) {
					$player = $lookup[$name];
					if ($player === null) {
						continue;
					}
					$blob .="<header2>{$name}<end>\n";
					$blob .= "<tab>- <highlight>{$name}<end> - {$player->level}/<green>{$player->ai_level}<end> {$player->profession} :: <red>in raid<end>\n";
					foreach ($alts as $alt => $inRaid) {
						$player = $lookup[$alt];
						$blob .= "<tab>- <highlight>{$alt}<end> - {$player->level}/<green>{$player->ai_level}<end> {$player->profession}";
						if ($inRaid) {
							$blob .= " :: <red>in raid<end>";
						}
						$blob .= "\n";
					}
					$blob .= "\n";
				}
				$msg = $this->text->makeBlob(
					"Dual-logged players (" . count($duals) .")",
					$blob,
					"Dual-logged players with at last 1 char in the raid"
				);
				$sendto->reply($msg);
			},
			$toLookup
		);
	}

	/**
	 * Log to the database whenever something of the raid changes
	 */
	public function logRaidChanges(Raid $raid): void {
		$this->db->table(self::DB_TABLE_LOG)
			->insert([
				"raid_id" => $raid->raid_id,
				"description" => $raid->description,
				"seconds_per_point" => $raid->seconds_per_point,
				"locked" => $raid->locked,
				"time" => time(),
				"announce_interval" => $raid->announce_interval,
			]);
	}

	/**
	 * @Event("sendpriv");
	 * @Description("Track when the bot sends messages on priv")
	 */
	public function trackOurPrivChannelMessages(AOChatEvent $event): void {
		if (!isset($this->raid)) {
			return;
		}
		$this->raid->we_are_most_recent_message = false;
	}

	/**
	 * @Event("priv");
	 * @Description("Track when someone sends messages on priv")
	 */
	public function trackPrivChannelMessages(AOChatEvent $event): void {
		if (!isset($this->raid) || $event->channel !== $this->chatBot->vars["name"]) {
			return;
		}
		$this->raid->we_are_most_recent_message = false;
	}

	/**
	 * Announce when a raid was started
	 * @Event("timer(30s)")
	 * @Description("Announce the running raid")
	 */
	public function announceRaidRunning(): void {
		if (!isset($this->raid) || $this->raid->announce_interval === 0) {
			return;
		}
		if (time() - $this->raid->last_announcement < $this->raid->announce_interval) {
			return;
		}
		if ($this->raid->we_are_most_recent_message) {
			return;
		}
		$this->chatBot->sendPrivate(
			$this->raid->getAnnounceMessage(
				$this->text->makeBlob(
					"click to join",
					$this->getRaidJoinLink(),
					"Raid information"
				)
			)
		);
		$this->raid->last_announcement = time();
		$this->raid->we_are_most_recent_message = true;
	}

	/**
	 * Announce when a raid was started
	 * @Event("raid(start)")
	 * @Description("Announce when a raid was started")
	 */
	public function announceRaidStart(RaidEvent $event): void {
		$this->chatBot->sendPrivate(
			"<highlight>{$event->raid->started_by}<end> started a raid: ".
			"<highlight>{$event->raid->description}<end> :: ".
			$this->text->makeBlob(
				"click to join",
				$this->getRaidJoinLink(),
				"Raid information"
			)
		);
	}

	/**
	 * Announce when a raid was stopped.
	 * @Event("raid(stop)")
	 * @Description("Announce when a raid is stopped")
	 */
	public function announceRaidStop(RaidEvent $event): void {
		$this->chatBot->sendPrivate("<highlight>{$event->player}<end> has stopped the raid.");
	}

	/**
	 * Start a new raid and also register it in the database
	 */
	public function startRaid(Raid $raid) {
		if (isset($raid->raid_id)) {
			$this->raid = $raid;
			return;
		}
		$raid->raid_id = $this->db->table(self::DB_TABLE)
			->insertGetId([
				"description" => $raid->description,
				"seconds_per_point" => $raid->seconds_per_point,
				"started" => $raid->started,
				"started_by" => $raid->started_by,
				"announce_interval" => $raid->announce_interval,
			], "raid_id");
		$this->raid = $raid;
		$event = new RaidEvent($raid);
		$event->type = "raid(start)";
		$event->player = $raid->started_by;
		$this->eventManager->fireEvent($event);
		$this->logRaidChanges($this->raid);
	}

	/**
	 * Stop the current raid
	 */
	public function stopRaid(string $sender) {
		if (!isset($this->raid)) {
			return;
		}
		$raid = $this->raid;
		$raid->stopped = time();
		$raid->stopped_by = $sender;
		$this->db->table(self::DB_TABLE)
			->where("raid_id", $raid->raid_id)
			->update([
				"stopped" => $raid->stopped,
				"stopped_by" => $raid->stopped_by,
			]);
		$this->raid = null;
		if ($this->settingManager->getBool('raid_stop_clears_callers')) {
			$this->chatAssistController->clearCallers($sender, "raid stop");
		}
		$event = new RaidEvent($raid);
		$event->type = "raid(stop)";
		$event->player = ucfirst(strtolower($sender));
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid (notes?|comments?)$/i")
	 */
	public function raidCommentsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== 'msg') {
			$sendto->reply("<red>The '<symbol>raid {$args[1]}' command only works in tells<end>.");
			return;
		}
		if (!isset($this->raid)) {
			$sendto->reply(static::ERR_NO_RAID);
			return;
		}
		$raiderNames = array_keys($this->raid->raiders);
		$category = $this->getRaidCategory();
		$comments = $this->commentController->getComments($category, ...$raiderNames);
		$comments = $this->commentController->filterInaccessibleComments($comments, $sender);
		if (!count($comments)) {
			$sendto->reply("There are no notes about any raider that you have access to.");
			return;
		}
		$format = $this->commentController->formatComments($comments, true);
		$msg = "Comments ({$format->numComments}) about the current raiders ({$format->numMains})";
		$msg = $this->text->makeBlob($msg, $format->blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid (?:notes?|comments?) (?:add|create|new) (\w+) (.+)$/i")
	 */
	public function raidCommentAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args = [$args[0], $args[1], $this->getRaidCategory()->name, $args[2]];
		$this->commentController->addCommentCommand(...func_get_args());
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid (?:notes?|comments?) (?:get|read|search|find) (\w+)$/i")
	 */
	public function raidCommentSearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args []= $this->getRaidCategory()->name;
		$this->commentController->searchCommentCommand(...func_get_args());
	}
}
