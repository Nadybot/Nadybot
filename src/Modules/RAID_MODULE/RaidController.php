<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes as NCA;
use DateTime;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	AOChatEvent,
	CmdContext,
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
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PWord;
use Nadybot\Modules\BASIC_CHAT_MODULE\ChatAssistController;
use Nadybot\Modules\COMMENT_MODULE\CommentCategory;
use Nadybot\Modules\COMMENT_MODULE\CommentController;
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

/**
 * This class contains all functions necessary to start, stsop and resume a raid
 * @package Nadybot\Modules\POINT_RAID_MODULE
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "raid",
		accessLevel: "all",
		description: "Check if the raid is running",
		help: "raid.txt"
	),
	NCA\DefineCommand(
		command: "raid .+",
		accessLevel: "raid_leader_1",
		description: "Everything to run a points raid",
		help: "raid.txt"
	),
	NCA\DefineCommand(
		command: "raid spp .+",
		accessLevel: "raid_leader_2",
		description: "Change the raid points ticker",
		help: "raid.txt"
	),
	NCA\ProvidesEvent("raid(start)"),
	NCA\ProvidesEvent("raid(stop)"),
	NCA\ProvidesEvent("raid(changed)"),
	NCA\ProvidesEvent("raid(lock)"),
	NCA\ProvidesEvent("raid(unlock)")
]
class RaidController {
	public const DB_TABLE = "raid_<myname>";
	public const DB_TABLE_LOG = "raid_log_<myname>";

	public string $moduleName;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public RaidMemberController $raidMemberController;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public CommentController $commentController;

	#[NCA\Inject]
	public RaidRankController $raidRankController;

	#[NCA\Inject]
	public OnlineController $onlineController;

	#[NCA\Inject]
	public ChatAssistController $chatAssistController;

	/**
	 * The currently running raid or null if none running
	 */
	public ?Raid $raid = null;

	public const ERR_NO_RAID = "There's currently no raid running.";
	public const CAT_RAID = "raid";

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'raid_announcement',
			description: 'Announce the raid periodically',
			mode: 'edit',
			type: 'options',
			value: '1',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'raid_announcement_interval',
			description: 'Announcement interval',
			mode: 'edit',
			type: 'time',
			value: '90s',
			options: '30s;60s;90s;120s;150s;180s',
			intoptions: '',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'raid_points_for_time',
			description: 'Give raid points based on duration of participation',
			mode: 'edit',
			type: 'options',
			value: '0',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'raid_points_interval',
			description: 'Point rate, in seconds',
			mode: 'edit',
			type: 'time',
			value: '5m',
			options: '',
			intoptions: '',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'raid_auto_add_creator',
			description: 'Add raid initiator to the raid',
			mode: 'edit',
			type: 'options',
			value: '1',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'raid_stop_clears_callers',
			description: 'Stopping the raid clears the callers',
			mode: 'edit',
			type: 'options',
			value: '0',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'raid_kick_notin_on_lock',
			description: 'Locking the raid kicks players not in the raid',
			mode: 'edit',
			type: 'options',
			value: '0',
			options:
				"Kick everyone not in the raid".
				";Kick all, except those who've been in the raid before".
				";Don't kick on raid lock",
			intoptions: '2;1;0',
			accessLevel: 'raid_admin_2'
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
		if (!isset($this->raid)) {
			return "";
		}
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
		if (!isset($this->raid)) {
			return "";
		}
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
					($this->settingManager->getInt('raid_announcement_interval')??90)
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

	#[NCA\HandlesCommand("raid")]
	public function raidCommand(CmdContext $context): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$handler = $this->commandManager->getActiveCommandHandler("raid", "priv", "raid start test");
		if (isset($handler)) {
			$canAdminRaid = $this->accessManager->checkAccess($context->char->name, $handler->admin);
			if ($canAdminRaid) {
				$this->chatBot->sendTell(
					$this->text->makeBlob("Raid Control", $this->getControlInterface()),
					$context->char->name
				);
			}
		}
		$msg = ((array)$this->text->makeBlob("click to join", $this->getRaidJoinLink(), "Raid information"))[0];
		$announceMsg = $this->raid->getAnnounceMessage($msg);
		$context->reply($announceMsg);
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

	#[NCA\HandlesCommand("raid .+")]
	public function raidStartCommand(CmdContext $context, #[NCA\Regexp("start|run|create")] string $action, string $description): void {
		if (isset($this->raid)) {
			$context->reply("There's already a raid running.");
			return;
		}
		$raid = new Raid();
		$raid->started_by = $context->char->name;
		$raid->description = $description;
		if ($this->settingManager->getBool('raid_announcement')) {
			$raid->announce_interval = $this->settingManager->getInt('raid_announcement_interval')??90;
		}
		if ($this->settingManager->getBool('raid_points_for_time')) {
			$raid->seconds_per_point = $this->settingManager->getInt('raid_points_interval')??300;
		}
		$this->startRaid($raid);
		if ($this->settingManager->getBool('raid_auto_add_creator')) {
			$this->raidMemberController->joinRaid($context->char->name, $context->char->name, $context->channel, false);
		}
		$this->chatBot->sendTell(
			$this->text->makeBlob("Raid Control", $this->getControlInterface()),
			$context->char->name
		);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidStopCommand(CmdContext $context, #[NCA\Regexp("stop|end")] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$this->stopRaid($context->char->name);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidChangeDescCommand(CmdContext $context, #[NCA\Regexp("description|descr?")] string $action, string $description): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$this->raid->description = $description;
		$this->logRaidChanges($this->raid);
		$context->reply("Raid description changed.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(change)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\HandlesCommand("raid spp .+")]
	public function raidChangeSppCommand(CmdContext $context, #[NCA\Str("spp")] string $action, int $spp): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$this->raid->seconds_per_point = $spp;
		$this->logRaidChanges($this->raid);
		$context->reply("Raid seconds per point changed.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(change)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidChangeAnnounceCommand(CmdContext $context, #[NCA\Regexp("announce|announcement")] string $action, string $interval): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		if (strtolower($interval) === 'off') {
			$this->raid->announce_interval = 0;
			$context->reply("Raid announcement turned off.");
		} else {
			$newInterval = $this->util->parseTime($interval);
			if ($newInterval === 0) {
				$context->reply("<highlight>{$interval}<end> is not a valid interval.");
				return;
			}
			$this->raid->announce_interval = $newInterval;
			$context->reply("Raid announcement interval changed to <highlight>{$interval}<end>.");
		}

		$this->logRaidChanges($this->raid);
		$event = new RaidEvent($this->raid);
		$event->type = "raid(change)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidLockCommand(CmdContext $context, #[NCA\Str("lock")] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		if ($this->raid->locked) {
			$context->reply("The raid is already locked.");
			return;
		}
		$this->raid->locked = true;
		$this->logRaidChanges($this->raid);
		$this->chatBot->sendPrivate("{$context->char->name} <red>locked<end> the raid.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(lock)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
		$notInKick = $this->settingManager->getInt('raid_kick_notin_on_lock')??0;
		if ($notInKick !== 0) {
			$this->raidMemberController->kickNotInRaid($this->raid, $notInKick === 2);
		}
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidUnlockCommand(CmdContext $context, #[NCA\Str("unlock")] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		if ($this->raid->locked === false) {
			$context->reply("The raid is already unlocked.");
			return;
		}
		$this->raid->locked = false;
		$this->logRaidChanges($this->raid);
		$this->chatBot->sendPrivate("{$context->char->name} <green>unlocked<end> the raid.");
		$event = new RaidEvent($this->raid);
		$event->type = "raid(unlock)";
		$event->player = $context->char->name;
		$this->eventManager->fireEvent($event);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidCheckCommand(CmdContext $context, #[NCA\Str("check")] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$this->raidMemberController->sendRaidCheckBlob($this->raid, $context);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidListCommand(CmdContext $context, #[NCA\Str("list")] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$context->reply($this->raidMemberController->getRaidListBlob($this->raid));
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidNotinKickCommand(CmdContext $context, #[NCA\Str("notinkick")] string $action, #[NCA\Str("all")] ?string $all): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$notInRaid = $this->raidMemberController->kickNotInRaid($this->raid, isset($all));
		$numKicked = count($notInRaid);
		if ($numKicked === 0) {
			$context->reply("Everyone is in the raid.");
			return;
		}
		$context->reply(
			"<highlight>{$numKicked} " . $this->text->pluralize("player", $numKicked).
			"<end> kicked, because they are not in the raid."
		);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidNotinCommand(CmdContext $context, #[NCA\Str("notin")] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$notInRaid = $this->raidMemberController->sendNotInRaidWarning($this->raid);
		if (!count($notInRaid)) {
			$context->reply("Everyone is in the raid.");
			return;
		}
		$this->playerManager->massGetByNameAsync(
			function(array $result) use ($context) {
				$this->reportNotInResult($result, $context);
			},
			$notInRaid
		);
	}

	protected function reportNotInResult(array $players, CommandReply $sendto): void {
		$blob = "<header2>Players that were warned<end>\n";
		ksort($players);
		foreach ($players as $name => $player) {
			if ($player instanceof Player && isset($player->profession)) {
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".
					($this->onlineController->getProfessionId($player->profession)??0).">";
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

	#[NCA\HandlesCommand("raid .+")]
	public function raidHistoryCommand(CmdContext $context, #[NCA\Str("history")] string $action): void {
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
			$context->reply($msg);
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
		$context->reply($msg);
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

	#[NCA\HandlesCommand("raid .+")]
	public function raidHistoryDetailCommand(CmdContext $context, #[NCA\Str("history")] string $action, int $raidId): void {
		/** @var ?Raid */
		$raid = $this->db->table(self::DB_TABLE)
			->where("raid_id", $raidId)
			->asObj(Raid::class)->first();
		if ($raid === null) {
			$context->reply("The raid <highlight>{$raidId}<end> doesn't exist.");
			return;
		}
		$query = $this->db->table(RaidPointsController::DB_TABLE_LOG)
			->where("raid_id", $raidId)
			->where("individual", false)
			->groupBy("username")
			->select("username");
		$query->addSelect($query->colFunc("SUM", "delta", "delta"));

		$noPoints = $this->db->table(RaidMemberController::DB_TABLE, "rm")
			->leftJoin(RaidPointsController::DB_TABLE_LOG . " as l", function (JoinClause $join) {
				$join->on("rm.raid_id", "l.raid_id")
					->on("rm.player", "l.username");
			})
			->where("rm.raid_id", $raidId)
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
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidHistoryDetailRaiderCommand(
		CmdContext $context,
		#[NCA\Str("history")] string $action,
		int $raidId,
		PCharacter $char
	): void {
		/** @var ?Raid */
		$raid = $this->db->table(self::DB_TABLE)
			->where('raid_id', $raidId)
			->asObj(Raid::class)
			->first();
		if ($raid === null) {
			$context->reply("The raid <highlight>{$raidId}<end> doesn't exist.");
			return;
		}
		/** @var Collection<RaidPointsLog> */
		$logs = $this->db->table(RaidPointsController::DB_TABLE_LOG)
			->where("raid_id", $raidId)
			->where("username", $char())
			->asObj(RaidPointsLog::class);
		$joined = $this->db->table(RaidMemberController::DB_TABLE)
			->where("raid_id", $raidId)
			->where("player", $char())
			->whereNotNull("joined")
			->select("joined AS time");
		$joined->selectRaw("1" . $joined->as("status"));
		$left = $this->db->table(RaidMemberController::DB_TABLE)
			->where("raid_id", $raidId)
			->where("player", $char())
			->whereNotNull("left")
			->select("left AS time");
		$left->selectRaw("0" . $left->as("status"));
		$events = $joined->union($left)->orderBy("time")->asObj();
		$allLogs = $logs->concat($events)
			->sort(function(object $a, object $b) {
				return $a->time <=> $b->time;
			});
		if ($allLogs->isEmpty()) {
			$context->reply("<highlight>{$char}<end> didn't get any points in this raid.");
			return;
		}
		$main = $this->altsController->getAltInfo($char())->main;
		$blob = $this->getRaidSummary($raid);
		$blob .= "\n<header2>Detailed points for {$char}";
		if ($main !== $char()) {
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
		$msg = $this->text->makeBlob("Raid {$raid->raid_id} details for {$char}", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidDualCommand(CmdContext $context, #[NCA\Str("dual")] string $action): void {
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
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
			$context->reply("No one is currently dual-logged.");
			return;
		}
		$toLookup = [];
		foreach ($duals as $name => $alts) {
			$toLookup = [...$toLookup, $name, ...array_keys($alts)];
		}
		$this->playerManager->massGetByNameAsync(
			function (array $lookup) use ($duals, $context): void {
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
				$context->reply($msg);
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

	#[NCA\Event(
		name: "sendpriv",
		description: "Track when the bot sends messages on priv"
	)]
	public function trackOurPrivChannelMessages(AOChatEvent $event): void {
		if (!isset($this->raid)) {
			return;
		}
		$this->raid->we_are_most_recent_message = false;
	}

	#[NCA\Event(
		name: "timer(30s)",
		description: "Announce the running raid"
	)]
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
				((array)$this->text->makeBlob(
					"click to join",
					$this->getRaidJoinLink(),
					"Raid information"
				))[0]
			)
		);
		$this->raid->last_announcement = time();
		$this->raid->we_are_most_recent_message = true;
	}

	/**
	 * Announce when a raid was started
	 */
	#[NCA\Event(
		name: "raid(start)",
		description: "Announce when a raid was started"
	)]
	public function announceRaidStart(RaidEvent $event): void {
		$this->chatBot->sendPrivate(
			"<highlight>{$event->raid->started_by}<end> started a raid: ".
			"<highlight>{$event->raid->description}<end> :: ".
			((array)$this->text->makeBlob(
				"click to join",
				$this->getRaidJoinLink(),
				"Raid information"
			))[0]
		);
	}

	/**
	 * Announce when a raid was stopped.
	 */
	#[NCA\Event(
		name: "raid(stop)",
		description: "Announce when a raid is stopped"
	)]
	public function announceRaidStop(RaidEvent $event): void {
		$this->chatBot->sendPrivate("<highlight>{$event->player}<end> has stopped the raid.");
	}

	/**
	 * Start a new raid and also register it in the database
	 */
	public function startRaid(Raid $raid): void {
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
	public function stopRaid(string $sender): void {
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

	#[NCA\HandlesCommand("raid .+")]
	public function raidCommentsCommand(CmdContext $context, #[NCA\Regexp("notes?|comments?")] string $action): void {
		if (!$context->isDM()) {
			$context->reply("<red>The '<symbol>raid {$action}' command only works in tells<end>.");
			return;
		}
		if (!isset($this->raid)) {
			$context->reply(static::ERR_NO_RAID);
			return;
		}
		$raiderNames = array_keys($this->raid->raiders);
		$category = $this->getRaidCategory();
		$comments = $this->commentController->getComments($category, ...$raiderNames);
		$comments = $this->commentController->filterInaccessibleComments($comments, $context->char->name);
		if (!count($comments)) {
			$context->reply("There are no notes about any raider that you have access to.");
			return;
		}
		$format = $this->commentController->formatComments($comments, true);
		$msg = "Comments ({$format->numComments}) about the current raiders ({$format->numMains})";
		$msg = $this->text->makeBlob($msg, $format->blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidCommentAddCommand(
		CmdContext $context,
		#[NCA\Regexp("notes?|comments?")] string $action,
		#[NCA\Regexp("add|create|new")] string $subAction,
		PCharacter $char,
		string $comment
	): void {
		$this->commentController->addCommentCommand(
			$context,
			"new",
			$char,
			new PWord($this->getRaidCategory()->name),
			$comment
		);
	}

	#[NCA\HandlesCommand("raid .+")]
	public function raidCommentSearchCommand(
		CmdContext $context,
		#[NCA\Regexp("notes?|comments?")] string $action,
		#[NCA\Regexp("get|read|search|find")] string $subAction,
		PCharacter $char
	): void {
		$this->commentController->searchCommentCommand(
			$context,
			"get",
			$char,
			new PWord($this->getRaidCategory()->name),
		);
	}
}
