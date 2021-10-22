<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use DateTime;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	DB,
	LoggerWrapper,
	Modules\ALTS\AltsController,
	Modules\ALTS\AltEvent,
	Nadybot,
	QueryBuilder,
	SettingManager,
	Text,
	Timer,
};
use Throwable;

/**
 * This class contains all functions necessary to deal with points in a raid
 *
 * @Instance
 * @package Nadybot\Modules\RAID_MODULE
 *
 * @DefineCommand(
 *     command       = 'raidpoints',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Add or remove points from all raiders',
 *     help          = 'raidpoints.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'points',
 *     accessLevel   = 'all',
 *     description   = 'Check how many raid points you have',
 *     help          = 'raidpoints_raiders.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'points log',
 *     accessLevel   = 'all',
 *     description   = 'Check how many raid points you gained when',
 *     help          = 'raidpoints_raiders.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'points log all',
 *     accessLevel   = 'all',
 *     description   = 'Check how many raid points you gained when on all alts',
 *     help          = 'raidpoints_raiders.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'points .+',
 *     accessLevel   = 'raid_admin_1',
 *     description   = 'Check the raid points of another raider',
 *     help          = 'raidpoints.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'pointsmod',
 *     accessLevel   = 'raid_admin_1',
 *     description   = 'Manipulate raid points of a single raider',
 *     help          = 'raidpoints.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'points top',
 *     accessLevel   = 'member',
 *     description   = 'Show the top 25 raiders',
 *     help          = 'raidpoints_raiders.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'reward',
 *     accessLevel   = 'member',
 *     description   = 'Show the raid rewards for the raids',
 *     help          = 'reward.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'reward .+',
 *     accessLevel   = 'raid_admin_1',
 *     description   = 'Create, Edit and Remove raid reward entries',
 *     help          = 'reward.txt'
 * )
 */
class RaidPointsController {
	public const DB_TABLE = "raid_points_<myname>";
	public const DB_TABLE_LOG = "raid_points_log_<myname>";
	public const DB_TABLE_REWARD = "raid_reward_<myname>";

	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public RaidController $raidController;

	/** @Inject */
	public RaidMemberController $raidMemberController;

	/** @Inject */
	public RaidBlockController $raidBlockController;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			"raid_share_points",
			"Share raid points across all alts",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
		$this->settingManager->add(
			$this->moduleName,
			"raid_top_amount",
			"How many raiders to show in top list",
			"edit",
			"number",
			"25"
		);
		$this->settingManager->add(
			$this->moduleName,
			"raid_points_reason_min_length",
			"Minimum length required for points add/rem",
			"edit",
			"number",
			"10"
		);
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Points");
		$this->commandAlias->register($this->moduleName, "reward", "rewards");
		$this->commandAlias->register($this->moduleName, "pointsmod add", "points add");
		$this->commandAlias->register($this->moduleName, "pointsmod rem", "points rem");
		$this->commandAlias->register($this->moduleName, "raidpoints reward", "raid reward");
		$this->commandAlias->register($this->moduleName, "raidpoints punish", "raid punish");
	}

	/**
	 * Give points when the ticker is enabled
	 * @Event("timer(1s)")
	 * @Description("Award points for raid participation")
	 */
	public function awardParticipationPoints(): void {
		$raid = $this->raidController->raid ?? null;
		if (
			$raid === null
			|| $raid->seconds_per_point === 0
			|| (time() - $raid->last_award_from_ticker) < $raid->seconds_per_point
		) {
			return;
		}
		$raid->last_award_from_ticker = time();
		foreach ($raid->raiders as $raider) {
			if ($raider->left !== null) {
				continue;
			}
			if ($this->raidBlockController->isBlocked($raider->player, RaidBlockController::POINTS_GAIN)) {
				continue;
			}
			$this->giveTickPoint($raider->player, $raid);
		}
	}

	/**
	 * Give $player a point for participation in raid $raid
	 */
	public function giveTickPoint(string $player, Raid $raid): string {
		$pointsChar = ucfirst(strtolower($player));
		$sharePoints = $this->settingManager->getBool('raid_share_points');
		if ($sharePoints) {
			$pointsChar = $this->altsController->getAltInfo($pointsChar)->main;
		}
		$raid->raiders[$player]->points++;
		$raid->raiders[$player]->pointsRewarded++;
		$updated = $this->db->table(self::DB_TABLE_LOG)
			->where("raid_id", $raid->raid_id)
			->where("username", $pointsChar)
			->where("ticker", true)
			->increment("delta", 1);
		if ($updated > 0) {
			$this->giveRaidPoints($pointsChar, 1);
			return $pointsChar;
		}
		$this->db->table(self::DB_TABLE_LOG)
			->insert([
				"username" => $pointsChar,
				"delta" => 1,
				"time" => time(),
				"changed_by" => $this->db->getMyname(),
				"individual" => false,
				"reason" => "raid participation",
				"ticker" => true,
				"individual" => false,
				"raid_id" => $raid->raid_id,
			]);
		$this->giveRaidPoints($pointsChar, 1);
		return $pointsChar;
	}

	/**
	 * Modify $player's raid points by $delta, logging reason, etc.
	 * @return string The name of the character (main) receiving the points
	 * @throws Exception on error
	 */
	public function modifyRaidPoints(string $player, int $delta, bool $individual, string $reason, string $changedBy, ?Raid $raid): string {
		$pointsChar = ucfirst(strtolower($player));
		$sharePoints = $this->settingManager->getBool('raid_share_points');
		if ($sharePoints) {
			$pointsChar = $this->altsController->getAltInfo($pointsChar)->main;
		}
		// If that player already received reward based points for this reward on an alt ignore this
		if (isset($raid) && isset($raid->pointsGiven[$pointsChar])) {
			return $pointsChar;
		}
		if (isset($raid) && isset($raid->raiders[$player])) {
			$raid->raiders[$player]->points += $delta;
			if ($individual) {
				$raid->raiders[$player]->pointsIndividual += $delta;
			} else {
				$raid->raiders[$player]->pointsRewarded += $delta;
			}
		}
		$inserted = $this->db->table(self::DB_TABLE_LOG)
			->insert([
				"username" =>   ucfirst(strtolower($player)),
				"delta" =>      $delta,
				"time" =>       time(),
				"changed_by" => $changedBy,
				"individual" => $individual,
				"reason" =>     $reason,
				"ticker" =>     false,
				"raid_id" =>    $raid->raid_id ?? null,
			]);
		if ($inserted === 0) {
			$this->logger->log('ERROR', "Error logging the change of {$delta} points for {$pointsChar}.");
			throw new Exception("Error recording the points delta of {$delta} for {$pointsChar}.");
		}
		if (!$this->giveRaidPoints($pointsChar, $delta)) {
			$this->logger->log('ERROR', "Error giving {$delta} points to {$pointsChar}.");
			throw new Exception("Error giving {$delta} points to {$pointsChar}.");
		}
		return $pointsChar;
	}

	/**
	 * Low level function to modify a player's points, returning success or not
	 */
	protected function giveRaidPoints(string $player, int $delta): bool {
		$updated = $this->db->table(self::DB_TABLE)
			->where("username", $player)
			->increment("points", $delta);
		if ($updated) {
			return true;
		}
		$inserted = $this->db->table(self::DB_TABLE)
			->insert([
				"username" => $player,
				"points" => $delta
			]);
		return $inserted > 0;
	}

	/**
	 * Give everyone in the raid $raid $delta points, authorized by $sender
	 * @return int Number of players receiving points
	 */
	public function awardRaidPoints(Raid $raid, string $sender, int $delta, ?string $reason=null): int {
		ksort($raid->raiders);
		$numReceivers = 0;
		$raid->pointsGiven = [];
		$reason ??= ($delta > 0) ? "reward" : "penalty";
		foreach ($raid->raiders as $raider) {
			if (
				$raider->left !== null
				|| $this->raidBlockController->isBlocked($raider->player, RaidBlockController::POINTS_GAIN)
			) {
				continue;
			}
			$mainChar = $this->modifyRaidPoints($raider->player, $delta, false, $reason, $sender, $raid);
			$raid->pointsGiven[$mainChar] = true;
			$numReceivers++;
		}
		$raid->pointsGiven = [];
		return $numReceivers;
	}

	/**
	 * Get this player's raid points, taking into consideration alts
	 */
	public function getRaidPoints(string $player): ?int {
		$pointsChar = ucfirst(strtolower($player));
		$sharePoints = $this->settingManager->getBool('raid_share_points');
		if ($sharePoints) {
			$pointsChar = $this->altsController->getAltInfo($pointsChar)->main;
		}
		return $this->getThisAltsRaidPoints($pointsChar);
	}

	/**
	 * Get this character's raid points, not taking into consideration any alts
	 */
	public function getThisAltsRaidPoints(string $player): ?int {
		$row = $this->db->table(self::DB_TABLE)
			->where("username", $player)
			->asObj()->first();
		if ($row === null) {
			return null;
		}
		return (int)$row->points;
	}

	/**
	 * @HandlesCommand("raidpoints")
	 * @Matches("/^raidpoints reward (\d+)$/i")
	 * @Matches("/^raidpoints reward (\d+) (.+)$/i")
	 * @Matches("/^raidpoints reward (.+)$/i")
	 */
	public function raidRewardCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raidController->raid)) {
			$sendto->reply(RaidController::ERR_NO_RAID);
			return;
		}
		$raid = $this->raidController->raid;
		if (!preg_match("/^\d+$/", $args[1])) {
			$reward = $this->getRaidReward($args[1]);
			if (!isset($reward)) {
				$sendto->reply("No predefined reward named <highlight>{$args[1]}<end> found.");
				return;
			}
			$args[1] = $reward->points;
			$args[2] = $reward->reason;
		}
		$numRecipients = $this->awardRaidPoints($raid, $sender, (int)$args[1], $args[2] ?? null);
		$msgs = $this->raidMemberController->getRaidListBlob($raid, true);
		$pointsGiven = "<highlight>{$args[1]}<end> points were given";
		if ($args[1] === '1') {
			$pointsGiven = "<highlight>1<end> point was given";
		}
		$pointsGiven .= " to all raiders (<highlight>{$numRecipients}<end>) by {$sender} :: ";
		foreach ($msgs as &$blob) {
			$blob = "$pointsGiven $blob";
		}
		$this->chatBot->sendPrivate($msgs);
	}

	/**
	 * @HandlesCommand("raidpoints")
	 * @Matches("/^raidpoints punish (\d+)$/i")
	 * @Matches("/^raidpoints punish (\d+) (.+)$/i")
	 */
	public function raidPunishCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raidController->raid)) {
			$sendto->reply(RaidController::ERR_NO_RAID);
			return;
		}
		$raid = $this->raidController->raid;
		$numRecipients = $this->awardRaidPoints($raid, $sender, (int)$args[1] * -1, $args[2] ?? null);
		$msgs = $this->raidMemberController->getRaidListBlob($raid, true);
		$pointsGiven = "<highlight>{$args[1]} points<end> were removed";
		if ($args[1] === '1') {
			$pointsGiven = "<highlight>1 point<end> was removed";
		}
		$pointsGiven .= " from all raiders ($numRecipients) by <highligh>{$sender}<end> :: ";
		foreach ($msgs as &$blob) {
			$blob = "$pointsGiven $blob";
		}
		$this->chatBot->sendPrivate($msgs);
	}

	/**
	 * @HandlesCommand("points")
	 * @Matches("/^points$/i")
	 */
	public function pointsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== 'msg') {
			$sendto->reply("<red>The <symbol>points command only works in tells<end>.");
			return;
		}
		$points = $this->getRaidPoints($sender) ?? 0;
		$sendto->reply("You have <highlight>{$points}<end> raid points.");
	}

	/**
	 * @HandlesCommand("points top")
	 * @Matches("/^points top$/i")
	 */
	public function pointsTopCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var RaidPoints[] */
		$topRaiders = $this->db->table(self::DB_TABLE)
			->orderByDesc("points")
			->limit($this->settingManager->getInt('raid_top_amount'))
			->asObj(RaidPoints::class)
			->toArray();
		if (count($topRaiders) === 0) {
			$sendto->reply("No raiders have received any points yet.");
			return;
		}
		$blob = "<header2>Top Raiders<end>";
		$maxDigits = strlen((string)$topRaiders[0]->points);
		foreach ($topRaiders as $raider) {
			$blob .= "\n<tab>" . $this->text->alignNumber($raider->points, $maxDigits) . "    {$raider->username}";
		}
		$sendto->reply(
			$this->text->makeBlob("Top raiders (" . count($topRaiders) . ")", $blob)
		);
	}

	/**
	 * @HandlesCommand("points log")
	 * @Matches("/^points log$/i")
	 */
	public function pointsLogCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showraidPoints($channel, $sender, $sendto, false, ...$this->getRaidpointLogsForChar($sender));
	}

	/**
	 * @HandlesCommand("points log all")
	 * @Matches("/^points log all$/i")
	 */
	public function pointsLogAllCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->showraidPoints($channel, $sender, $sendto, true, ...$this->getRaidpointLogsForAccount($sender));
	}

	public function showraidPoints(string $channel, string $sender, CommandReply $sendto, bool $showUsername, RaidPointsLog ...$pointLogs): void {
		if ($channel !== 'msg') {
			$sendto->reply("<red>The <symbol>points log command only works in tells<end>.");
			return;
		}
		if (count($pointLogs) === 0) {
			$sendto->reply("You have never received any raid points at <myname>.");
			return;
		}
		[$header, $blob] = $this->getPointsLogBlob($pointLogs, $showUsername);
		if ($showUsername === false) {
			$blob .= "\n\n<i>Only showing the points of {$sender}. To include all the alts ".
				"in the list, use ".
				$this->text->makeChatcmd("/tell <myname> points log all", "/tell <myname> points log all").
				".</i>";
		}
		$msg = $this->text->makeBlob("Your raid points log", $blob, null, $header);
		$sendto->reply($msg);
	}

	/**
	 * Get all the raidpoint log entries for main and confirmed alts of $sender
	 *
	 * @return RaidPointsLog[]
	 */
	protected function getRaidpointLogsForAccount(string $sender): array {
		$altInfo = $this->altsController->getAltInfo($sender);
		$main = $altInfo->main;
		return $this->db->table(self::DB_TABLE_LOG, "rpl")
			->leftJoin("alts AS a", "a.alt", "rpl.username")
			->where(function (QueryBuilder $where) use ($main) {
				$where->where("a.main", $main)
					->where("a.validated_by_main", true)
					->where("a.validated_by_alt", true);
			})
			->orWhere("rpl.username", $main)
			->orderByDesc("time")
			->limit(50)
			->asObj(RaidPointsLog::class)
			->toArray();
	}

	/**
	 * Get all the raidpoint log entries for a single character $sender, not
	 * including alts
	 *
	 * @return RaidPointsLog[]
	 */
	protected function getRaidpointLogsForChar(string $sender): array {
		return $this->db->table(self::DB_TABLE_LOG)
			->where("username", $sender)
			->orderByDesc("time")
			->limit(50)
			->asObj(RaidPointsLog::class)
			->toArray();
	}

	/**
	 * @HandlesCommand("points .+")
	 * @Matches("/^points (.+) log (all)$/i")
	 * @Matches("/^points log (.+) (all)$/i")
	 * @Matches("/^points (.+) log$/i")
	 * @Matches("/^points log (.+)$/i")
	 */
	public function pointsOtherLogCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== 'msg') {
			$sendto->reply("<red>The <symbol>points log command only works in tells<end>.");
			return;
		}
		$args[1] = ucfirst(strtolower($args[1]));
		/** @var RaidPointsLog[] */
		if (count($args) === 3) {
			$pointLogs = $this->getRaidpointLogsForAccount($args[1]);
		} else {
			$pointLogs = $this->getRaidpointLogsForChar($args[1]);
		}
		if (count($pointLogs) === 0) {
			$sendto->reply("{$args[1]} has never received any raid points at <myname>.");
			return;
		}
		[$header, $blob] = $this->getPointsLogBlob($pointLogs, count($args) === 3);
		if (count($args) < 3) {
			$blob .= "\n\n<i>Only showing the points of {$args[1]}. To include all the alts ".
				"in the list, use ".
				$this->text->makeChatcmd("/tell <myname> {$message} all", "/tell <myname> {$message} all").
				".</i>";
		}
		$msg = $this->text->makeBlob("{$args[1]}'s raid points log", $blob, null, $header);
		$sendto->reply($msg);
	}

	/**
	 * Get the popup text with a detailed with of all points given/taken
	 * @param RaidPointsLog[] $pointLogs
	 * @return string[] Header and The popup text
	 */
	public function getPointsLogBlob(array $pointLogs, bool $showUsername=false): array {
		$header =  "<header2><u>When                       |   Delta   |  Why                              </u><end>\n";
		$rows = [];
		foreach ($pointLogs as $log) {
			$time = DateTime::createFromFormat("U", (string)$log->time)->format("Y-m-d H:i:s");
			if ($log->individual) {
				$log->reason = "<highlight>{$log->reason}<end>";
				$time = "<highlight>{$time}<end>";
			}
			$row = "$time  |  ".
				(($log->delta > 0) ? '+' : '-').
				$this->text->alignNumber(abs($log->delta), 4, $log->delta > 0 ? 'green' : 'red').
				"  |  {$log->reason} ({$log->changed_by})";
			if ($showUsername) {
				$row .= " on {$log->username}";
			}
			$rows []= $row;
		}
		return [$header, join("\n", $rows)];
	}

	/**
	 * @HandlesCommand("points .+")
	 * @Matches("/^points (.+)$/i")
	 */
	public function pointsOtherCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== 'msg') {
			$sendto->reply("<red>The <symbol>points command only works in tells<end>.");
			return;
		}
		$args[1] = ucfirst(strtolower($args[1]));
		$points = $this->getRaidPoints($args[1]);
		if ($points === null) {
			$sendto->reply("<highlight>{$args[1]}<end> has never raided with this bot.");
			return;
		}
		$sendto->reply("<highlight>{$args[1]}<end> has <highlight>{$points}<end> raid points.");
	}

	/**
	 * @HandlesCommand("pointsmod")
	 * @Matches("/^pointsmod add ([^ ]+) (\d+) (.+)$/i")
	 * @Matches("/^pointsmod add (\d+) ([^ ]+) (.+)$/i")
	 */
	public function pointsAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$receiver = $args[1];
		$delta = $args[2];
		if (preg_match('/^\d+$/', $args[1])) {
			$receiver = $args[2];
			$delta = $args[1];
		}
		$receiver = ucfirst(strtolower($receiver));
		$uid = $this->chatBot->get_uid($receiver);
		if ($uid === false) {
			$sendto->reply("The player <highlight>{$receiver}<end> does not exist.");
			return;
		}
		if (strlen($args[3]) < $this->settingManager->getInt('raid_points_reason_min_length')) {
			$sendto->reply("Please give a more detailed description.");
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->modifyRaidPoints($receiver, (int)$delta, true, $args[3], $sender, $raid);
		$this->chatBot->sendPrivate("<highlight>{$sender}<end> added <highlight>{$delta}<end> points to ".
			"<highlight>{$receiver}'s<end> account: <highlight>{$args[3]}<end>.");
		$this->chatBot->sendTell(
			"Added <highlight>{$delta}<end> raid points to <highlight>{$receiver}'s<end> account.",
			$sender
		);
		$this->chatBot->sendMassTell(
			"{$sender} added <highlight>{$delta}<end> raid points to your account.",
			$receiver
		);
	}

	/**
	 * @HandlesCommand("pointsmod")
	 * @Matches("/^pointsmod rem ([^ ]+) (\d+) (.+)$/i")
	 * @Matches("/^pointsmod rem (\d+) ([^ ]+) (.+)$/i")
	 */
	public function pointsRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$receiver = $args[1];
		$delta = $args[2];
		if (preg_match('/^\d+$/', $args[1])) {
			$receiver = $args[2];
			$delta = $args[1];
		}
		$receiver = ucfirst(strtolower($receiver));
		$uid = $this->chatBot->get_uid($receiver);
		if ($uid === false) {
			$sendto->reply("The player <highlight>{$receiver}<end> does not exist.");
			return;
		}
		if (strlen($args[3]) < $this->settingManager->getInt('raid_points_reason_min_length')) {
			$sendto->reply("Please give a more detailed description.");
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->modifyRaidPoints($receiver, -1 * (int)$delta, true, $args[3], $sender, $raid);
		$this->chatBot->sendPrivate("<highlight>{$sender}<end> removed <highlight>{$delta}<end> points from ".
			"<highlight>{$receiver}'s<end> account: <highlight>{$args[3]}<end>.");
	}

	/**
	 * Give points when the ticker is enabled
	 * @Event("alt(add)")
	 * @Event("alt(validate)")
	 * @Description("Merge raid points when alts merge")
	 */
	public function mergeRaidPoints(AltEvent $event): void {
		if ($event->validated === false) {
			return;
		}
		if (!$this->settingManager->getBool('raid_share_points')) {
			return;
		}
		$altsPoints = $this->getThisAltsRaidPoints($event->alt);
		if ($altsPoints === null) {
			return;
		}
		if ($this->db->inTransaction()) {
			$this->timer->callLater(0, [$this, "mergeRaidPoints"], $event);
			return;
		}
		$mainPoints = $this->getThisAltsRaidPoints($event->main);
		$this->logger->log(
			'INFO',
			"Adding {$event->alt} as an alt of {$event->main} requires us to merge their raid points. ".
			"Combining {$event->alt}'s points ({$altsPoints}) with {$event->main}'s (".
			($mainPoints??0) . ")"
		);
		$this->db->beginTransaction();
		try {
			$newPoints = $altsPoints + ($mainPoints??0);
			$this->db->table(self::DB_TABLE)
				->upsert(
					[
						"username" => $event->main,
						"points" => $newPoints,
					],
					["username"]
				);
			$this->db->table(self::DB_TABLE)
				->where("username", $event->alt)
				->delete();
		} catch (Throwable $e) {
			$this->db->rollback();
			$this->logger->log(
				'ERROR',
				'There was an error combining these points: ' . $e->getMessage()
			);
			return;
		}
		$this->db->commit();
		$this->logger->log(
			'INFO',
			'Raid points merged successfully to a new total of ' . $newPoints
		);
	}

	/**
	 * @HandlesCommand("reward")
	 * @Matches("/^reward$/i")
	 */
	public function rewardListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Collection<RaidReward> */
		$rewards = $this->db->table(self::DB_TABLE_REWARD)
			->orderBy("name")
			->asObj(RaidReward::class);
		if ($rewards->isEmpty()) {
			$sendto->reply("There are currently no raid rewards defined.");
			return;
		}
		$blob = "";
		foreach ($rewards as $reward) {
			$remCmd = $this->text->makeChatcmd("Remove", "/tell <myname> reward rem {$reward->id}");
			$giveCmd = $this->text->makeChatcmd("Give", "/tell <myname> raid reward {$reward->name}");
			$blob .= "<header2>{$reward->name}<end>\n".
				"<tab>Points: <highlight>{$reward->points}<end> [{$giveCmd}]\n".
				"<tab>Log: <highlight>{$reward->reason}<end>\n".
				"<tab>ID: <highlight>{$reward->id}<end> [{$remCmd}]\n\n";
		}
		$msg = $this->text->makeBlob("Raid rewards (" . count($rewards). ")", $blob);
		$sendto->reply($msg);
	}

	public function getRaidReward(string $name): ?RaidReward {
		return $this->db->table(self::DB_TABLE_REWARD)
			->whereIlike("name", $name)
			->asObj(RaidReward::class)->first();
	}

	/**
	 * @HandlesCommand("reward .+")
	 * @Matches("/^reward add ([^ ]+) (\d+) (.+)$/i")
	 */
	public function rewardAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($this->getRaidReward($args[1])) {
			$sendto->reply("The raid reward <highlight>{$args[1]}<end> is already defined.");
			return;
		}
		$reward = new RaidReward();
		$reward->name = $args[1];
		$reward->points = (int)$args[2];
		$reward->reason = $args[3];
		if (strlen($reward->name) > 20) {
			$sendto->reply("The name of the reward is too long. Maximum is 20 characters.");
			return;
		}
		if (strlen($reward->reason) > 100) {
			$sendto->reply("The name of the log entry is too long. Maximum is 100 characters.");
			return;
		}
		$this->db->insert(self::DB_TABLE_REWARD, $reward);
		$sendto->reply("New reward <highlight>{$reward->name}<end> created.");
	}

	/**
	 * @HandlesCommand("reward .+")
	 * @Matches("/^reward (?:rem|del) (.+)$/i")
	 */
	public function rewardRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = $args[1];
		if (!preg_match("/^\d+$/", $args[1])) {
			$reward = $this->getRaidReward($args[1]);
			if (!isset($reward)) {
				$sendto->reply("The raid reward <highlight>{$args[1]}<end> does not exist.");
				return;
			}
			$id = $reward->id;
			$name = $reward->name;
		} else {
			$id = (int)$args[1];
		}
		$deleted = $this->db->table(self::DB_TABLE_REWARD)->delete($id);
		if ($deleted) {
			$sendto->reply("Raid reward <highlight>{$name}<end> successfully deleted.");
		} else {
			$sendto->reply("Raid reward <highlight>{$name}<end> was not found.");
		}
	}

	/**
	 * @HandlesCommand("reward .+")
	 * @Matches("/^reward (?:change|edit|alter|mod|modify) ([^ ]+) (\d+)$/i")
	 * @Matches("/^reward (?:change|edit|alter|mod|modify) ([^ ]+) (\d+) (.+)$/i")
	 */
	public function rewardChangeCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$reward = $this->getRaidReward($args[1]);
		if (!isset($reward)) {
			$sendto->reply("The raid reward <highlight>{$args[1]}<end> is not yet defined.");
			return;
		}
		$reward->name = $args[1];
		$reward->points = (int)$args[2];
		$reward->reason = $args[3] ?? $reward->reason;
		if (strlen($reward->name) > 20) {
			$sendto->reply("The name of the reward is too long. Maximum is 20 characters.");
			return;
		}
		if (strlen($reward->reason) > 100) {
			$sendto->reply("The name of the log entry is too long. Maximum is 100 characters.");
			return;
		}
		$this->db->update(self::DB_TABLE_REWARD, "id", $reward);
		$sendto->reply("Reward <highlight>{$reward->name}<end> changed.");
	}

	/**
	 * @Event("alt(newmain)")
	 * @Description("Move raid points to new main")
	 */
	public function moveRaidPoints(AltEvent $event): void {
		$sharePoints = $this->settingManager->getBool('raid_share_points');
		if (!$sharePoints) {
			return;
		}
		$oldPoints = $this->getThisAltsRaidPoints($event->alt);
		if ($oldPoints === null) {
			return;
		}
		$this->db->table(self::DB_TABLE)
			->upsert(
				["username" => $event->main, "points" => $oldPoints],
				["username"]
			);
		$this->db->table(self::DB_TABLE)
			->where("username", $event->alt)
			->delete();
		$this->logger->log('INFO', "Moved {$oldPoints} raid points from {$event->alt} to {$event->main}.");
	}

	/**
	 * @NewsTile("raid")
	 * @Description("Shows the player's amount of raid points and if a raid
	 * is currently running.")
	 * @Example("<header2>Raid<end>
	 * <tab>You have <highlight>2222<end> raid points.
	 * <tab>Raid is running: <highlight>Test raid, everyone join<end> :: [<u>join bot</u>] [<u>join raid</u>]")
	 */
	public function raidpointsTile(string $sender, callable $callback): void {
		$points = $this->getRaidPoints($sender);
		$raid = $this->raidController->raid ?? null;
		if ($points === null && $raid === null) {
			$callback(null);
			return;
		}
		$blob = "<header2>Raid<end>";
		if ($points !== null) {
			$blob .= "\n<tab>You have <highlight>{$points}<end> raid points.";
		}
		if ($raid !== null) {
			$blob .= "\n<tab>" . $raid->getAnnounceMessage().
				"[" . $this->text->makeChatcmd("join bot", "/tell <myname> join") . "] ".
				"[" . $this->text->makeChatcmd("join raid", "/tell <myname> raid join") . "]";
		}
		$callback($blob);
	}
}
