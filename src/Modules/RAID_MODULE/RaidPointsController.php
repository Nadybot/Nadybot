<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Safe\DateTime;
use Exception;
use Illuminate\Support\Collection;
use Throwable;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	DB,
	ModuleInstance,
	LoggerWrapper,
	Modules\ALTS\AltsController,
	Modules\ALTS\AltEvent,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PNonNumber,
	ParamClass\PNonNumberWord,
	ParamClass\PRemove,
	ParamClass\PWord,
	SettingManager,
	Text,
	Timer,
};

/**
 * This class contains all functions necessary to deal with points in a raid
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Points"),
	NCA\DefineCommand(
		command: RaidPointsController::CMD_RAID_REWARD_PUNISH,
		accessLevel: "raid_leader_1",
		description: "Add or remove points from all raiders",
	),
	NCA\DefineCommand(
		command: "points",
		accessLevel: "all",
		description: "Check how many raid points you have",
	),
	NCA\DefineCommand(
		command: RaidPointsController::CMD_POINTS_OTHER,
		accessLevel: "raid_admin_1",
		description: "Check the raid points of another raider",
	),
	NCA\DefineCommand(
		command: RaidPointsController::CMD_POINTS_MODIFY,
		accessLevel: "raid_admin_1",
		description: "Manipulate raid points of a single raider",
	),
	NCA\DefineCommand(
		command: "points top",
		accessLevel: "member",
		description: "Show the top raiders",
	),
	NCA\DefineCommand(
		command: "reward",
		accessLevel: "member",
		description: "Show the raid rewards for the raids",
		alias: 'rewards'
	),
	NCA\DefineCommand(
		command: RaidPointsController::CMD_REWARD_EDIT,
		accessLevel: "raid_admin_1",
		description: "Create, Edit and Remove raid reward entries",
	)
]
class RaidPointsController extends ModuleInstance {
	public const DB_TABLE = "raid_points_<myname>";
	public const DB_TABLE_LOG = "raid_points_log_<myname>";
	public const DB_TABLE_REWARD = "raid_reward_<myname>";

	public const CMD_RAID_REWARD_PUNISH = "raid reward/punish";
	public const CMD_POINTS_OTHER = "points see other";
	public const CMD_POINTS_MODIFY = "points modify";
	public const CMD_REWARD_EDIT = "reward add/change/delete";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public RaidController $raidController;

	#[NCA\Inject]
	public RaidMemberController $raidMemberController;

	#[NCA\Inject]
	public RaidBlockController $raidBlockController;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "raid_share_points",
			description: "Share raid points across all alts",
			mode: "edit",
			type: "options",
			value: "1",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "raid_top_amount",
			description: "How many raiders to show in top list",
			mode: "edit",
			type: "number",
			value: "25"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "raid_points_reason_min_length",
			description: "Minimum length required for points add/rem",
			mode: "edit",
			type: "number",
			value: "10"
		);
	}

	/**
	 * Give points when the ticker is enabled
	 */
	#[NCA\Event(
		name: "timer(1s)",
		description: "Award points for raid participation"
	)]
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
			$pointsChar = $this->altsController->getMainOf($pointsChar);
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
			$pointsChar = $this->altsController->getMainOf($pointsChar);
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
		if ($inserted === false) {
			$this->logger->error("Error logging the change of {$delta} points for {$pointsChar}.");
			throw new Exception("Error recording the points delta of {$delta} for {$pointsChar}.");
		}
		if (!$this->giveRaidPoints($pointsChar, $delta)) {
			$this->logger->error("Error giving {$delta} points to {$pointsChar}.");
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
			$pointsChar = $this->altsController->getMainOf($pointsChar);
		}
		return $this->getThisAltsRaidPoints($pointsChar);
	}

	/**
	 * Get this character's raid points, not taking into consideration any alts
	 */
	public function getThisAltsRaidPoints(string $player): ?int {
		return $this->db->table(self::DB_TABLE)
			->where("username", $player)
			->select("points")
			->pluckAs("points", "int")
			->first();
	}

	/** Reward everyone in the raid a pre-defined reward for &lt;mob&gt; */
	#[NCA\HandlesCommand(self::CMD_RAID_REWARD_PUNISH)]
	#[NCA\Help\Group("raid-points")]
	public function raidRewardPredefCommand(
		CmdContext $context,
		#[NCA\Str("reward")] string $action,
		PNonNumber $mob
	): void {
		$reward = $this->getRaidReward($mob());
		if (!isset($reward)) {
			$context->reply("No predefined reward named <highlight>{$mob}<end> found.");
			return;
		}
		$this->raidRewardCommand($context, $action, $reward->points, $reward->reason);
	}

	/** Reward everyone in the raid points */
	#[NCA\HandlesCommand(self::CMD_RAID_REWARD_PUNISH)]
	#[NCA\Help\Group("raid-points")]
	public function raidRewardCommand(
		CmdContext $context,
		#[NCA\Str("reward")] string $action,
		int $points,
		?string $reason
	): void {
		if (!isset($this->raidController->raid)) {
			$context->reply(RaidController::ERR_NO_RAID);
			return;
		}
		$raid = $this->raidController->raid;
		$numRecipients = $this->awardRaidPoints($raid, $context->char->name, $points, $reason);
		$msgs = $this->raidMemberController->getRaidListBlob($raid, true);
		$pointsGiven = "<highlight>{$points}<end> points were given";
		if ($points === 1) {
			$pointsGiven = "<highlight>1<end> point was given";
		}
		$pointsGiven .= " to all raiders (<highlight>{$numRecipients}<end>) by {$context->char->name} :: ";
		foreach ($msgs as &$blob) {
			$blob = "$pointsGiven $blob";
		}
		$this->chatBot->sendPrivate($msgs);
	}

	/** Remove raidpoints from everyone in the raid */
	#[NCA\HandlesCommand(self::CMD_RAID_REWARD_PUNISH)]
	#[NCA\Help\Group("raid-points")]
	public function raidPunishCommand(
		CmdContext $context,
		#[NCA\Str("punish")] string $action,
		int $points,
		?string $reason
	): void {
		if (!isset($this->raidController->raid)) {
			$context->reply(RaidController::ERR_NO_RAID);
			return;
		}
		$raid = $this->raidController->raid;
		$numRecipients = $this->awardRaidPoints($raid, $context->char->name, $points * -1, $reason);
		$msgs = $this->raidMemberController->getRaidListBlob($raid, true);
		$pointsGiven = "<highlight>{$points} points<end> were removed";
		if ($points === 1) {
			$pointsGiven = "<highlight>1 point<end> was removed";
		}
		$pointsGiven .= " from all raiders ($numRecipients) by <highligh>{$context->char->name}<end> :: ";
		foreach ($msgs as &$blob) {
			$blob = "$pointsGiven $blob";
		}
		$this->chatBot->sendPrivate($msgs);
	}

	/** Check how many raid points you have */
	#[NCA\HandlesCommand("points")]
	#[NCA\Help\Group("raid-points")]
	public function pointsCommand(CmdContext $context): void {
		if (!$context->isDM()) {
			$context->reply("<red>The <symbol>points command only works in tells<end>.");
			return;
		}
		$points = $this->getRaidPoints($context->char->name) ?? 0;
		$context->reply("You have <highlight>{$points}<end> raid points.");
	}

	/** See the top list of raiders point-wise */
	#[NCA\HandlesCommand("points top")]
	#[NCA\Help\Group("raid-points")]
	public function pointsTopCommand(
		CmdContext $context,
		#[NCA\Str("top")] string $action
	): void {
		/** @var RaidPoints[] */
		$topRaiders = $this->db->table(self::DB_TABLE)
			->orderByDesc("points")
			->limit($this->settingManager->getInt('raid_top_amount')??25)
			->asObj(RaidPoints::class)
			->toArray();
		if (count($topRaiders) === 0) {
			$context->reply("No raiders have received any points yet.");
			return;
		}
		$blob = "<header2>Top Raiders<end>";
		$maxDigits = strlen((string)$topRaiders[0]->points);
		foreach ($topRaiders as $raider) {
			$blob .= "\n<tab>" . $this->text->alignNumber($raider->points, $maxDigits) . "    {$raider->username}";
		}
		$context->reply(
			$this->text->makeBlob("Top raiders (" . count($topRaiders) . ")", $blob)
		);
	}

	/** See when your current character or 'all' your alts have received raid points and for what */
	#[NCA\HandlesCommand("points")]
	#[NCA\Help\Group("raid-points")]
	public function pointsLogCommand(
		CmdContext $context,
		#[NCA\Str("log")] string $action,
		#[NCA\Str("all")] ?string $all
	): void {
		$this->showraidPoints($context, isset($all), ...$this->getRaidpointLogsForChar($context->char->name));
	}

	public function showraidPoints(CmdContext $context, bool $showUsername, RaidPointsLog ...$pointLogs): void {
		if (!$context->isDM()) {
			$context->reply("<red>The <symbol>points log command only works in tells<end>.");
			return;
		}
		if (count($pointLogs) === 0) {
			$context->reply("You have never received any raid points at <myname>.");
			return;
		}
		[$header, $blob] = $this->getPointsLogBlob($pointLogs, $showUsername);
		if ($showUsername === false) {
			$blob .= "\n\n<i>Only showing the points of {$context->char->name}. To include all the alts ".
				"in the list, use ".
				$this->text->makeChatcmd("/tell <myname> points log all", "/tell <myname> points log all").
				".</i>";
		}
		$msg = $this->text->makeBlob("Your raid points log", $blob, null, $header);
		$context->reply($msg);
	}

	/**
	 * Get all the raidpoint log entries for main and confirmed alts of $sender
	 * @return RaidPointsLog[]
	 */
	protected function getRaidpointLogsForAccount(string $sender): array {
		$main = $this->altsController->getMainOf($sender);
		$alts = $this->altsController->getAltsOf($main);
		return  $this->db->table(self::DB_TABLE_LOG)
			->whereIn("username", array_merge([$sender], $alts))
			->orderByDesc("time")
			->limit(50)
			->asObj(RaidPointsLog::class)
			->toArray();
	}

	/**
	 * Get all the raidpoint log entries for a single character $sender, not
	 * including alts
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

	/** See a history of &lt;char&gt;'s raid points. Add 'all' to include all alts */
	#[NCA\HandlesCommand(self::CMD_POINTS_OTHER)]
	#[NCA\Help\Group("raid-points")]
	public function pointsOtherLogCommand(
		CmdContext $context,
		PCharacter $char,
		#[NCA\Str("log")] string $action,
		#[NCA\Str("all")] ?string $all
	): void {
		$this->pointsLogOtherCommand($context, $action, $char, $all);
	}

	/** See a history of &lt;char&gt;'s raid points. Add 'all' to include all alts */
	#[NCA\HandlesCommand(self::CMD_POINTS_OTHER)]
	#[NCA\Help\Group("raid-points")]
	public function pointsLogOtherCommand(
		CmdContext $context,
		#[NCA\Str("log")] string $action,
		PCharacter $char,
		#[NCA\Str("all")] ?string $all
	): void {
		if (!$context->isDM()) {
			$context->reply("<red>The <symbol>points log command only works in tells<end>.");
			return;
		}
		$char = $char();
		if (isset($all)) {
			$pointLogs = $this->getRaidpointLogsForAccount($char);
		} else {
			$pointLogs = $this->getRaidpointLogsForChar($char);
		}
		/** @var RaidPointsLog[] $pointLogs */
		if (count($pointLogs) === 0) {
			$context->reply("{$char} has never received any raid points at <myname>.");
			return;
		}
		[$header, $blob] = $this->getPointsLogBlob($pointLogs, isset($all));
		if (!isset($all)) {
			$blob .= "\n\n<i>Only showing the points of {$char}. To include all the alts ".
				"in the list, use ".
				$this->text->makeChatcmd("/tell <myname> {$context->message} all", "/tell <myname> {$context->message} all").
				".</i>";
		}
		$msg = $this->text->makeBlob("{$char}'s raid points log", $blob, null, $header);
		$context->reply($msg);
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

	/** See &lt;char&gt;'s raid points */
	#[NCA\HandlesCommand(self::CMD_POINTS_OTHER)]
	#[NCA\Help\Group("raid-points")]
	public function pointsOtherCommand(CmdContext $context, PCharacter $char): void {
		if (!$context->isDM()) {
			$context->reply("<red>The <symbol>points command only works in tells<end>.");
			return;
		}
		$points = $this->getRaidPoints($char());
		if ($points === null) {
			$context->reply("<highlight>{$char}<end> has never raided with this bot.");
			return;
		}
		$context->reply("<highlight>{$char}<end> has <highlight>{$points}<end> raid points.");
	}

	/** Add &lt;points&gt; raid points to &lt;char&gt;'s account with a reason */
	#[NCA\HandlesCommand(self::CMD_POINTS_MODIFY)]
	#[NCA\Help\Group("raid-points")]
	public function pointsAdd2Command(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		int $points,
		PCharacter $char,
		string $reason
	): void {
		$this->pointsAddCommand($context, $action, $char, $points, $reason);
	}

	/** Add &lt;points&gt; raid points to &lt;char&gt;'s account with a reason */
	#[NCA\HandlesCommand(self::CMD_POINTS_MODIFY)]
	#[NCA\Help\Group("raid-points")]
	public function pointsAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PCharacter $char,
		int $points,
		string $reason
	): void {
		$receiver = $char();
		$uid = $this->chatBot->get_uid($receiver);
		if ($uid === false) {
			$context->reply("The player <highlight>{$receiver}<end> does not exist.");
			return;
		}
		if (strlen($reason) < $this->settingManager->getInt('raid_points_reason_min_length')) {
			$context->reply("Please give a more detailed description.");
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->modifyRaidPoints($receiver, $points, true, $reason, $context->char->name, $raid);
		$this->chatBot->sendPrivate("<highlight>{$context->char->name}<end> added <highlight>{$points}<end> points to ".
			"<highlight>{$receiver}'s<end> account: <highlight>{$reason}<end>.");
		$this->chatBot->sendTell(
			"Added <highlight>{$points}<end> raid points to <highlight>{$receiver}'s<end> account.",
			$context->char->name
		);
		$this->chatBot->sendMassTell(
			"{$context->char->name} added <highlight>{$points}<end> raid points to your account.",
			$receiver
		);
	}

	/** Remove &lt;points&gt; raid points from &lt;char&gt;'s account with a reason */
	#[NCA\HandlesCommand(self::CMD_POINTS_MODIFY)]
	#[NCA\Help\Group("raid-points")]
	public function pointsRem2Command(
		CmdContext $context,
		PRemove $action,
		int $points,
		PCharacter $char,
		string $reason
	): void {
		$this->pointsRemCommand($context, $action, $char, $points, $reason);
	}

	/** Remove &lt;points&gt; raid points from &lt;char&gt;'s account with a reason */
	#[NCA\HandlesCommand(self::CMD_POINTS_MODIFY)]
	#[NCA\Help\Group("raid-points")]
	public function pointsRemCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $char,
		int $points,
		string $reason
	): void {
		$receiver = $char();
		$uid = $this->chatBot->get_uid($receiver);
		if ($uid === false) {
			$context->reply("The player <highlight>{$receiver}<end> does not exist.");
			return;
		}
		if (strlen($reason) < $this->settingManager->getInt('raid_points_reason_min_length')) {
			$context->reply("Please give a more detailed description.");
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->modifyRaidPoints($receiver, -1 * $points, true, $reason, $context->char->name, $raid);
		$this->chatBot->sendPrivate("<highlight>{$context->char->name}<end> removed <highlight>{$points}<end> points from ".
			"<highlight>{$receiver}'s<end> account: <highlight>{$reason}<end>.");
	}

	/**
	 * Give points when the ticker is enabled
	 */
	#[
		NCA\Event(
			name: ["alt(add)", "alt(validate)"],
			description: "Merge raid points when alts merge"
		)
	]
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
		$this->logger->notice(
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
			$this->logger->error(
				'There was an error combining these points: ' . $e->getMessage()
			);
			return;
		}
		$this->db->commit();
		$this->logger->notice(
			'Raid points merged successfully to a new total of ' . $newPoints
		);
	}

	/** See a list of pre-defined raid rewards */
	#[NCA\HandlesCommand("reward")]
	public function rewardListCommand(CmdContext $context): void {
		/** @var Collection<RaidReward> */
		$rewards = $this->db->table(self::DB_TABLE_REWARD)
			->orderBy("name")
			->asObj(RaidReward::class);
		if ($rewards->isEmpty()) {
			$context->reply("There are currently no raid rewards defined.");
			return;
		}
		$blob = "";
		foreach ($rewards as $reward) {
			$remCmd = $this->text->makeChatcmd("remove", "/tell <myname> reward rem {$reward->id}");
			$giveCmd = $this->text->makeChatcmd("give", "/tell <myname> raid reward {$reward->name}");
			$blob .= "<header2>{$reward->name}<end>\n".
				"<tab>Points: <highlight>{$reward->points}<end> [{$giveCmd}]\n".
				"<tab>Log: <highlight>{$reward->reason}<end>\n".
				"<tab>ID: <highlight>{$reward->id}<end> [{$remCmd}]\n\n";
		}
		$msg = $this->text->makeBlob("Raid rewards (" . count($rewards). ")", $blob);
		$context->reply($msg);
	}

	public function getRaidReward(string $name): ?RaidReward {
		return $this->db->table(self::DB_TABLE_REWARD)
			->whereIlike("name", $name)
			->asObj(RaidReward::class)->first();
	}

	/** Create a new pre-defined raid reward with a name, points and reason */
	#[NCA\HandlesCommand(self::CMD_REWARD_EDIT)]
	#[NCA\Help\Example("<symbol>reward add beast 80 Beast kill")]
	#[NCA\Help\Example("<symbol>reward add zod 25 Zodiac")]
	#[NCA\Help\Example("<symbol>reward add capri 25 Capricorn")]
	public function rewardAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PWord $name,
		int $points,
		string $reason
	): void {
		if ($this->getRaidReward($name())) {
			$context->reply("The raid reward <highlight>{$name}<end> is already defined.");
			return;
		}
		$reward = new RaidReward();
		$reward->name = $name();
		$reward->points = $points;
		$reward->reason = $reason;
		if (strlen($reward->name) > 20) {
			$context->reply("The name of the reward is too long. Maximum is 20 characters.");
			return;
		}
		if (strlen($reward->reason) > 100) {
			$context->reply("The name of the log entry is too long. Maximum is 100 characters.");
			return;
		}
		$this->db->insert(self::DB_TABLE_REWARD, $reward);
		$context->reply("New reward <highlight>{$reward->name}<end> created.");
	}

	/** Remove a pre-defined raid reward */
	#[NCA\HandlesCommand(self::CMD_REWARD_EDIT)]
	public function rewardRemCommand(
		CmdContext $context,
		PRemove $action,
		PNonNumberWord $name
	): void {
		$reward = $this->getRaidReward($name());
		if (!isset($reward)) {
			$context->reply("The raid reward <highlight>{$name}<end> does not exist.");
			return;
		}
		$this->rewardRemIdCommand($context, $action, $reward->id);
	}

	/** Remove a pre-defined raid reward */
	#[NCA\HandlesCommand(self::CMD_REWARD_EDIT)]
	public function rewardRemIdCommand(CmdContext $context, PRemove $action, int $id): void {
		$deleted = $this->db->table(self::DB_TABLE_REWARD)->delete($id);
		if ($deleted) {
			$context->reply("Raid reward <highlight>#{$id}<end> successfully deleted.");
		} else {
			$context->reply("Raid reward <highlight>#{$id}<end> was not found.");
		}
	}

	/** Change a pre-defined raid reward */
	#[NCA\HandlesCommand(self::CMD_REWARD_EDIT)]
	#[NCA\Help\Example("<symbol>reward change beast 120 Beast kill")]
	public function rewardChangeCommand(
		CmdContext $context,
		#[NCA\Str("change", "edit", "alter", "mod", "modify")] string $action,
		PWord $name,
		int $points,
		?string $reason
	): void {
		$reward = $this->getRaidReward($name());
		if (!isset($reward)) {
			$context->reply("The raid reward <highlight>{$name}<end> is not yet defined.");
			return;
		}
		$reward->name = $name();
		$reward->points = $points;
		$reward->reason = $reason ?? $reward->reason;
		if (strlen($reward->name) > 20) {
			$context->reply("The name of the reward is too long. Maximum is 20 characters.");
			return;
		}
		if (strlen($reward->reason) > 100) {
			$context->reply("The name of the log entry is too long. Maximum is 100 characters.");
			return;
		}
		$this->db->update(self::DB_TABLE_REWARD, "id", $reward);
		$context->reply("Reward <highlight>{$reward->name}<end> changed.");
	}

	#[NCA\Event(
		name: "alt(newmain)",
		description: "Move raid points to new main"
	)]
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
		$this->logger->notice("Moved {$oldPoints} raid points from {$event->alt} to {$event->main}.");
	}

	#[
		NCA\NewsTile(
			name: "raid",
			description:
				"Shows the player's amount of raid points and if a raid\n".
				"is currently running.",
			example:
				"<header2>Raid<end>\n".
				"<tab>You have <highlight>2222<end> raid points.\n".
				"<tab>Raid is running: <highlight>Test raid, everyone join<end> :: [<u>join bot</u>] [<u>join raid</u>]"
		)
	]
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
