<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use DateTime;
use Exception;
use Nadybot\Core\{
	CommandReply,
	DB,
	LoggerWrapper,
	Modules\ALTS\AltsController,
	Modules\ALTS\AltEvent,
	Nadybot,
	SettingManager,
	SQLException,
	Text,
};

/**
 * This class contains all functions necessary to deal with points in a raid
 *
 * @Instance
 * @package Nadybot\Modules\RAID_MODULE
 *
 * @DefineCommand(
 *     command       = 'raid reward .+',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Reward people for raid participation',
 *     help          = 'raidpoints.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'raid punish .+',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Punish people for accidentally given points',
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
 *     command       = 'points .+',
 *     accessLevel   = 'raid_admin_1',
 *     description   = 'Check the raid points of another raider',
 *     help          = 'raidpoints.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'points (add|rem) .+',
 *     accessLevel   = 'raid_admin_1',
 *     description   = 'Manipulate raid points of another raider',
 *     help          = 'raidpoints.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'points top',
 *     accessLevel   = 'member',
 *     description   = 'Show the top 25 raiders',
 *     help          = 'raidpoints_raiders.txt'
 * )
 */
class RaidPointsController {
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public RaidController $raidController;

	/** @Inject */
	public RaidMemberController $raidMemberController;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

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
		$this->db->loadSQLFile($this->moduleName, "raid_points");
		$this->db->loadSQLFile($this->moduleName, "raid_points_log");
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
			$this->giveTickPoint($raider->player, $raid);
		}
	}

	/**
	 * Give $player a point for participation in raid $raid
	 */
	public function giveTickPoint(string $player, Raid $raid): string {
		$this->logger->log('INFO', 'Raid points ticker');
		$pointsChar = ucfirst(strtolower($player));
		$sharePoints = $this->settingManager->getBool('raid_share_points');
		if ($sharePoints) {
			$pointsChar = $this->altsController->getAltInfo($pointsChar)->main;
		}
		$raid->raiders[$player]->points++;
		$updated = $this->db->exec(
			"UPDATE raid_points_log_<myname> SET delta=delta+1 ".
			"WHERE raid_id=? AND username=? AND ticker IS TRUE",
			$raid->raid_id,
			$pointsChar,
		);
		if ($updated > 0) {
			return $pointsChar;
		}
		$inserted = $this->db->exec(
			"INSERT INTO raid_points_log_<myname> ".
			"(`username`, `delta`, `time`, `changed_by`, `reason`, `ticker`, `raid_id`) ".
			"VALUES(?, ?, ?, '<Myname>', ?, ?, ?)",
			$pointsChar,
			1,
			time(),
			'raid participation',
			true,
			$raid->raid_id
		);
		$this->giveRaidPoints($pointsChar, 1);
		return $pointsChar;
	}

	/**
	 * Modify $player's raid points by $delta, logging reason, etc.
	 * @return string The name of the character (main) receiving the points
	 * @throws Exception on error
	 */
	public function modifyRaidPoints(string $player, int $delta, string $reason, string $changedBy, ?Raid $raid): string {
		$pointsChar = ucfirst(strtolower($player));
		$sharePoints = $this->settingManager->getBool('raid_share_points');
		if ($sharePoints) {
			$pointsChar = $this->altsController->getAltInfo($pointsChar)->main;
		}
		// If that player already received reward based points for this reward on an alt ignore this
		if (isset($raidd) && isset($raid->pointsGiven[$pointsChar])) {
			return $pointsChar;
		}
		if (isset($raid) && isset($raid->raiders[$player])) {
			$raid->raiders[$player]->points += $delta;
		}
		$inserted = $this->db->exec(
			"INSERT INTO raid_points_log_<myname> ".
			"(`username`, `delta`, `time`, `changed_by`, `reason`, `ticker`, `raid_id`) ".
			"VALUES(?, ?, ?, ?, ?, ?, ?)",
			$pointsChar,
			$delta,
			time(),
			$changedBy,
			$reason,
			false,
			$raid->raid_id ?? null
		);
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
	 * Low level function to modify a player's points, returning sucess or not
	 */
	protected function giveRaidPoints(string $player, int $delta): bool {
		$updated = $this->db->exec(
			"UPDATE raid_points_<myname> SET points=points+? WHERE username=?",
			$delta,
			$player
		);
		if ($updated) {
			return true;
		}
		$inserted = $this->db->exec(
			"INSERT INTO raid_points_<myname> (`username`, `points`) ".
			"VALUES(?, ?)",
			$player,
			$delta
		);
		return $inserted > 0;
	}

	/**
	 * Give everyone in the raid $raid $delta points, authorized by $sender
	 * @return int Number of players receiving points
	 */
	public function awardRaidPoints(Raid $raid, string $sender, int $delta): int {
		ksort($raid->raiders);
		$numReceivers = 0;
		$raid->pointsGiven = [];
		foreach ($raid->raiders as $raider) {
			if ($raider->left !== null) {
				continue;
			}
			$mainChar = $this->modifyRaidPoints($raider->player, $delta, ($delta > 0) ? "reward" : "penalty", $sender, $raid);
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
		$row = $this->db->queryRow("SELECT * FROM `raid_points_<myname>` WHERE `username`=?", $player);
		if ($row === null) {
			return null;
		}
		return (int)$row->points;
	}

	/**
	 * @HandlesCommand("raid reward .+")
	 * @Matches("/^raid reward (\d+)$/i")
	 */
	public function raidRewardCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raidController->raid)) {
			$sendto->reply(RaidController::ERR_NO_RAID);
			return;
		}
		$raid = $this->raidController->raid;
		$numRecipients = $this->awardRaidPoints($raid, $sender, (int)$args[1]);
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
	 * @HandlesCommand("raid punish .+")
	 * @Matches("/^raid punish (\d+)$/i")
	 */
	public function raidPunishCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->raidController->raid)) {
			$sendto->reply(RaidController::ERR_NO_RAID);
			return;
		}
		$raid = $this->raidController->raid;
		$numRecipients = $this->awardRaidPoints($raid, $sender, (int)$args[1] * -1);
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
		$topRaiders = $this->db->fetchAll(
			RaidPoints::class,
			"SELECT * FROM raid_points_<myname> ".
			"ORDER BY points DESC LIMIT ?",
			$this->settingManager->getInt('raid_top_amount')
		);
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
		if ($channel !== 'msg') {
			$sendto->reply("<red>The <symbol>points log command only works in tells<end>.");
			return;
		}
		/** @var RaidPointsLog[] */
		$pointLogs = $this->db->fetchAll(
			RaidPointsLog::class,
			"SELECT * FROM `raid_points_log_<myname>` ".
			"WHERE `username`=? ORDER BY `time` DESC LIMIT 50",
			$sender
		);
		if (count($pointLogs) === 0) {
			$sendto->reply("You have never received any raid points at <myname>.");
			return;
		}
		[$header, $blob] = $this->getPointsLogBlob($pointLogs);
		$msg = $this->text->makeBlob("Your raid points log", $blob, null, $header);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("points .+")
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
		$pointLogs = $this->db->fetchAll(
			RaidPointsLog::class,
			"SELECT * FROM `raid_points_log_<myname>` ".
			"WHERE `username`=? ORDER BY `time` DESC LIMIT 50",
			$args[1]
		);
		if (count($pointLogs) === 0) {
			$sendto->reply("{$args[1]} has never received any raid points at <myname>.");
			return;
		}
		[$header, $blob] = $this->getPointsLogBlob($pointLogs);
		$msg = $this->text->makeBlob("{$args[1]}'s raid points log", $blob, null, $header);
		$sendto->reply($msg);
	}

	/**
	 * Get the popup text with a detailed with of all points given/taken
	 * @param RaidPointsLog[] $pointLogs
	 * @return string[] Header and The popup text
	 */
	public function getPointsLogBlob(array $pointLogs): array {
		$header =  "<header2><u>When                       |   Delta   |  Why                              </u><end>\n";
		$rows = [];
		foreach ($pointLogs as $log) {
			$time = DateTime::createFromFormat("U", (string)$log->time)->format("Y-m-d H:i:s");
			$row = "$time  |  ".
				(($log->delta > 0) ? '+' : '-').
				$this->text->alignNumber(abs($log->delta), 4, $log->delta > 0 ? 'green' : 'red').
				"  |  {$log->reason} ({$log->changed_by})";
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
	 * @HandlesCommand("points (add|rem) .+")
	 * @Matches("/^points add ([^ ]+) (\d+) (.+)$/i")
	 * @Matches("/^points add (\d+) ([^ ]+) (.+)$/i")
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
		if (strlen($args[3]) < 10) {
			$sendto->reply("Please give a more detailed description.");
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->modifyRaidPoints($receiver, (int)$delta, $args[3], $sender, $raid);
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
	 * @HandlesCommand("points (add|rem) .+")
	 * @Matches("/^points rem ([^ ]+) (\d+) (.+)$/i")
	 * @Matches("/^points rem (\d+) ([^ ]+) (.+)$/i")
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
		if (strlen($args[3]) < 10) {
			$sendto->reply("Please give a more detailed description.");
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->modifyRaidPoints($receiver, -1 * (int)$delta, $args[3], $sender, $raid);
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
		$mainPoints = $this->getThisAltsRaidPoints($event->main);
		$this->logger->log(
			'INFO',
			"Adding {$event->alt} as an alt of {$event->main} requires us to merge their raid points. ".
			"Combining {$event->alt}'s points ({$altsPoints}) with {$event->main}'s (".
			($mainPoints??0) . ")"
		);
		$this->db->beginTransaction();
		try {
			if ($mainPoints === null) {
				$this->db->exec(
					"INSERT INTO raid_points_<myname> (`username`, `points`) VALUES (?, ?)",
					$event->main,
					$altsPoints
				);
			} else {
				$this->db->exec(
					"UPDATE raid_points_<myname> SET `points`=? WHERE `username`=?",
					$altsPoints + $mainPoints,
					$event->main
				);
			}
			$this->db->exec(
				"DELETE FROM raid_points_<myname> WHERE `username`=?",
				$event->alt
			);
		} catch (SQLException $e) {
			$this->db->rollback();
			$this->logger->log('ERROR', 'There was an error combining these points');
			return;
		}
		$this->db->commit();
		$this->logger->log(
			'INFO',
			'Raid points merged successfully to a new total of '.
			($mainPoints??0 + $altsPoints)
		);
	}
}
