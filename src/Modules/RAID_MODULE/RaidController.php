<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use DateTime;
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
	public RaidRankController $raidRankController;

	/** @Inject */
	public OnlineController $onlineController;

	/**
	 * The currently running raid or null if none running
	 */
	public ?Raid $raid = null;

	public const ERR_NO_RAID = "There's currently no raid running.";
		
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
		$this->db->loadSQLFile($this->moduleName, "raid");
		$this->db->loadSQLFile($this->moduleName, "raid_log");
		$this->timer->callLater(0, [$this, 'resumeRaid']);
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
		$blob .= "Raid anouncement: <highlight>";
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
			$sendto->reply(
				$this->text->makeBlob("Raid Control", $this->getControlInterface())
			);
			return;
		}
		$msg = $this->text->makeBlob("click to join", $this->getRaidJoinLink(), "Raid information");
		$sendto->reply($this->raid->getAnnounceMessage($msg));
	}

	/**
	 * Try to resume a raid that was already running when the bot shut down
	 */
	public function resumeRaid(): void {
		/** @var ?Raid */
		$lastRaid = $this->db->fetch(
			Raid::class,
			"SELECT * FROM `raid_<myname>` ORDER BY `raid_id` DESC LIMIT 1"
		);
		if ($lastRaid === null || $lastRaid->stopped) {
			return;
		}
		/** @var ?RaidLog */
		$lastRaidLog = $this->db->fetch(
			RaidLog::class,
			"SELECT * FROM `raid_log_<myname>` WHERE `raid_id`=? ".
			"ORDER BY `time` DESC LIMIT 1",
			$lastRaid->raid_id
		);
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
		$sendto->reply($this->raidMemberController->getRaidCheckBlob($this->raid));
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid list$/i")
	 */
	public function raidListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sendto->reply($this->raidMemberController->getRaidListBlob($this->raid));
	}

	/**
	 * @HandlesCommand("raid .+")
	 * @Matches("/^raid notin$/i")
	 */
	public function raidNotinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$notInRaid = $this->raidMemberController->sendNotInRaidWarning($this->raid);
		if (!count($notInRaid)) {
			$sendto->reply("Everyone is in the raid.");
			return;
		}
		$blob = "<header2>Players that were warned<end>\n";
		foreach ($notInRaid as $player) {
			if ($player instanceof Player) {
				$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".
					$this->onlineController->getProfessionId($player->profession).">";
				$blob .= "<tab>{$profIcon} {$player->name} - {$player->level}/{$player->ai_level}\n";
			} else {
				$blob .= "<tab>{$player}\n";
			}
		}
		$msgs = (array)$this->text->makeBlob(count($notInRaid) . " players", $blob, "Players not in the raid");
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
		$raids = $this->db->query(
			"SELECT r.raid_id, r.started, r.stopped, ".
			"COUNT(DISTINCT(`username`)) AS raiders, ".
			"SUM(`delta`) AS points ".
			"FROM `raid_<myname>` r ".
			"JOIN `raid_points_log_<myname>` p ON (r.raid_id=p.raid_id) ".
			"WHERE p.`reason` IN ('reward', 'penalty') OR p.`ticker` IS TRUE ".
			"GROUP BY r.`raid_id` ".
			"ORDER BY r.`raid_id` DESC LIMIT ?",
			50
		);
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
		$raid = $this->db->fetch(
			Raid::class,
			"SELECT * FROM `raid_<myname>` WHERE `raid_id`=?",
			(int)$args[1]
		);
		if ($raid === null) {
			$sendto->reply("The raid <highlight>{$args[1]}<end> doesn't exist.");
			return;
		}
		/** @var RaidPointsLog[] */
		$raiders = $this->db->fetchAll(
			RaidPointsLog::class,
			"SELECT `username`, SUM(`delta`) AS `delta` FROM `raid_points_log_<myname>` ".
			"WHERE `raid_id`=? ".
			"AND (`reason` IN ('reward', 'penalty') OR `ticker` IS TRUE) ".
			"GROUP BY `username` ORDER BY `username` ASC",
			(int)$args[1]
		);
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
		$raid = $this->db->fetch(
			Raid::class,
			"SELECT * FROM `raid_<myname>` WHERE `raid_id`=?",
			(int)$args[1]
		);
		if ($raid === null) {
			$sendto->reply("The raid <highlight>{$args[1]}<end> doesn't exist.");
			return;
		}
		/** @var RaidPointsLog[] */
		$logs = $this->db->fetchAll(
			RaidPointsLog::class,
			"SELECT * FROM `raid_points_log_<myname>` ".
			"WHERE `raid_id`=? AND `username`=?",
			(int)$args[1],
			$args[2]
		);
		if (!count($logs)) {
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
		foreach ($logs as $log) {
			$blob .= "<tab>" . $this->util->date($log->time) . "<tab>".
				$this->text->alignNumber(abs($log->delta), 5, $log->delta > 0 ? 'green' : 'red').
				" - {$log->reason}  (by {$log->changed_by})\n";
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
			foreach ($altInfo->alts as $alt => $true) {
				if ($alt === $name) {
					continue;
				}
				if (!isset($this->chatBot->chatlist[$alt])) {
					continue;
				}
				$duals[$name] ??= [];
				$duals[$name][$alt] = isset($this->raid->raiders[$alt]);
			}
		}
		if (!count($duals)) {
			$sendto->reply("No one is currently dual-logged.");
			return;
		}
		$blob = "";
		foreach ($duals as $name => $alts) {
			$player = $this->playerManager->getByName($name);
			if ($player === null) {
				continue;
			}
			$blob .="<header2>{$name}<end>\n";
			$blob .= "<tab>- <highlight>{$name}<end> - {$player->level}/<green>{$player->ai_level}<end> {$player->profession} :: <red>in raid<end>\n";
			foreach ($alts as $alt => $inRaid) {
				$player = $this->playerManager->getByName($alt);
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
	}

	/**
	 * Log to the database whenever something of the raid changes
	 */
	public function logRaidChanges(Raid $raid): void {
		$this->db->exec(
			"INSERT INTO `raid_log_<myname>` (`raid_id`, `description`, `seconds_per_point`, `locked`, `time`, `announce_interval`) ".
			"VALUES (?, ?, ?, ?, ?, ?)",
			$raid->raid_id,
			$raid->description,
			$raid->seconds_per_point,
			$raid->locked,
			time(),
			$raid->announce_interval
		);
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
		$this->db->exec(
			"INSERT INTO `raid_<myname>` ".
			"(`description`, `seconds_per_point`, `started`, `started_by`, `announce_interval`) ".
			"VALUES (?, ?, ?, ?, ?)",
			$raid->description,
			$raid->seconds_per_point,
			$raid->started,
			$raid->started_by,
			$raid->announce_interval
		);
		$raid->raid_id = $this->db->lastInsertId();
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
		$this->db->exec(
			"UPDATE `raid_<myname>` SET `stopped`=?, `stopped_by`=? WHERE `raid_id`=? AND `stopped` IS NULL",
			$raid->stopped,
			$raid->stopped_by,
			$raid->raid_id
		);
		$this->raid = null;
		$event = new RaidEvent($raid);
		$event->type = "raid(stop)";
		$event->player = ucfirst(strtolower($sender));
		$this->eventManager->fireEvent($event);
	}
}
