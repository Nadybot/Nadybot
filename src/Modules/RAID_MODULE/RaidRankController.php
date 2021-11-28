<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	AdminManager,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandReply,
	DB,
	DBSchema\Audit,
	LoggerWrapper,
	Modules\ALTS\AltEvent,
	Modules\ALTS\AltsController,
	Nadybot,
	SettingManager,
	Text,
};
use Nadybot\Core\ParamClass\PCharacter;

/**
 * @Instance
 * Commands this controller contains:
 * @DefineCommand(
 *     command       = 'raidadmin',
 *     accessLevel   = 'raid_admin_2',
 *     description   = 'Promote/demote someone to/from raid admin',
 *     help          = 'raidranks.txt'
 * )
 * @DefineCommand(
 *     command       = 'raidleader',
 *     accessLevel   = 'raid_admin_1',
 *     description   = 'Promote/demote someone to/from raid leader',
 *     help          = 'raidranks.txt'
 * )
 *	@DefineCommand(
 *		command       = 'leaderlist',
 *		accessLevel   = 'all',
 *		description   = 'Shows the list of raid leaders and admins',
 *		help          = 'leaderlist.txt',
 *		alias         = 'leaders',
 *		defaultStatus = '1'
 *	)
 */
class RaidRankController {
	public const DB_TABLE = "raid_rank_<myname>";

	public string $moduleName;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AdminManager $adminManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,RaidRank> */
	public array $ranks = [];

	/**
	 * @Setup
	 * @todo: Add support for the raid levels
	 */
	public function setup(): void {
		/**
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_level_1',
			'Name of the raid points rank 1',
			'edit',
			'text',
			'Experienced Raider'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_level_2',
			'Name of the raid points rank 2',
			'edit',
			'text',
			'Veteran Raider'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_level_3',
			'Name of the raid points rank 3',
			'edit',
			'text',
			'Elite Raider'
		);
		*/

		$this->settingManager->add(
			$this->moduleName,
			'raid_rank_promotion_distance',
			'Number of raid ranks below your own you can manage',
			'edit',
			'number',
			'1'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_leader_1',
			'Name of the raid leader rank 1',
			'edit',
			'text',
			'Apprentice Leader'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_leader_2',
			'Name of the raid leader rank 2',
			'edit',
			'text',
			'Leader'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_leader_3',
			'Name of the raid leader rank 3',
			'edit',
			'text',
			'Veteran Leader'
		);

		$this->settingManager->add(
			$this->moduleName,
			'name_raid_admin_1',
			'Name of the raid admin rank 1',
			'edit',
			'text',
			'Apprentice Raid Admin'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_admin_2',
			'Name of the raid admin rank 2',
			'edit',
			'text',
			'Raid Admin'
		);
		$this->settingManager->add(
			$this->moduleName,
			'name_raid_admin_3',
			'Name of the raid admin rank 3',
			'edit',
			'text',
			'Veteran Raid Admin'
		);
		$this->settingManager->add(
			$this->moduleName,
			'raid_duration_recently',
			'Duration considered "recent" in raid stats for leaders command',
			'edit',
			'options',
			'2592000',
			'Off;1 Month;3 Months;6 Months;1 Year',
			'0;2592000;7776000;15552000;31536000'
		);
		$this->commandAlias->register($this->moduleName, "raidadmin", "raid admin");
		$this->commandAlias->register($this->moduleName, "raidleader", "raid leader");
	}

	/**
	 * @Event("connect")
	 * @Description("Add raid leader and admins to the buddy list")
	 * @DefaultStatus("1")
	 */
	public function checkRaidRanksEvent(): void {
		$this->db->table(self::DB_TABLE)
			->asObj(RaidRank::class)
			->each(function (RaidRank $row) {
				$this->buddylistManager->add($row->name, 'raidrank');
			});
	}

	/**
	 * Load the raid leaders, admins and veterans from the database into $ranks
	 * @Setup
	 */
	public function uploadRaidRanks(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Ranks");
		$this->db->table(self::DB_TABLE)
			->asObj(RaidRank::class)
			->each(function (RaidRank $row) {
				$this->ranks[$row->name] = $row;
			});
	}

	/**
	 * Demote someone's special raid rank
	 */
	public function removeFromLists(string $who, string $sender): void {
		$oldRank = $this->ranks[$who]??null;
		unset($this->ranks[$who]);
		$this->db->table(self::DB_TABLE)
			->where("name", $who)
			->delete();
		$this->buddylistManager->remove($who, 'raidrank');
		if (isset($oldRank)) {
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->actee = $who;
			$audit->action = AccessManager::DEL_RANK;
			$audit->value = (string)($this->accessManager->getAccessLevels()["raid_leader_1"] - ($oldRank->rank-4));
			$this->accessManager->addAudit($audit);
		}
	}

	/**
	 * Set the raid rank of a user
	 *
	 * @return string Either "demoted" or "promoted"
	 */
	public function addToLists(string $who, string $sender, int $rank): string {
		$oldRank = $this->ranks[$who]??null;
		$action = 'promoted';
		if (isset($this->ranks[$who]) && $this->ranks[$who]->rank > $rank) {
			$action = "demoted";
		}
		$this->db->table(self::DB_TABLE)
			->upsert(
				["rank" => $rank, "name" => $who],
				["name"]
			);

		if (isset($oldRank)) {
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->actee = $who;
			$audit->action = AccessManager::DEL_RANK;
			$audit->value = (string)($this->accessManager->getAccessLevels()["raid_leader_1"] - ($oldRank->rank-4));
			$this->accessManager->addAudit($audit);
		}

		$this->ranks[$who] ??= new RaidRank();
		$this->ranks[$who]->rank = $rank;
		$this->ranks[$who]->name = $who;
		$this->buddylistManager->add($who, 'raidrank');

		$audit = new Audit();
		$audit->actor = $sender;
		$audit->actee = $who;
		$audit->action = AccessManager::ADD_RANK;
		$audit->value = (string)($this->accessManager->getAccessLevels()["raid_leader_1"] - ($rank-4));
		$this->accessManager->addAudit($audit);

		return $action;
	}

	/**
	 * Check if a user $who has raid rank $rank
	 */
	public function checkExisting(string $who, int $rank): bool {
		return ($this->ranks[$who]->rank??-1) === $rank;
	}

	/**
	 * Chheck if $actor's access level is higher than $actee's
	 */
	public function checkAccessLevel(string $actor, string $actee): bool {
		$senderAccessLevel = $this->accessManager->getAccessLevelForCharacter($actor);
		$whoAccessLevel = $this->accessManager->getSingleAccessLevel($actee);
		return $this->accessManager->compareAccessLevels($whoAccessLevel, $senderAccessLevel) < 0;
	}

	/** Check if $sender can change $who's raid rank (to $newRank or in general) */
	public function canChangeRaidRank(string $sender, string $who, ?string $newRank, CommandReply $sendto): bool {
		if (!$this->checkAccessLevel($sender, $who)) {
			$sendto->reply("You must have a higher access level than <highlight>$who<end> in order to change their access level.");
			return false;
		}
		$reqDistance = $this->settingManager->getInt('raid_rank_promotion_distance') ?? 1;
		$accessLevels = $this->accessManager->getAccessLevels();
		$senderAccessLevel = $this->accessManager->getAccessLevel(
			$this->accessManager->getAccessLevelForCharacter($sender)
		);
		$oldAccessLevel = $this->accessManager->getAccessLevel(
			$this->accessManager->getAccessLevelForCharacter($who)
		);
		$newAccessLevel = $oldAccessLevel;
		if (isset($newRank)) {
			$newAccessLevel = $this->accessManager->getAccessLevel($newRank);
		}
		$numSenderAccessLevel = $accessLevels[$senderAccessLevel];
		$numOldAccessLevel = $accessLevels[$oldAccessLevel];
		$numSettableAL = $numSenderAccessLevel + $reqDistance;
		$numNewAccessLevel = $accessLevels[$newAccessLevel];
		if ($numNewAccessLevel < $numSettableAL || $numOldAccessLevel < $numSettableAL) {
			$reverseALs = array_flip($accessLevels);
			$nameSettableAL = $this->accessManager->getDisplayName($reverseALs[$numSettableAL]);
			$sendto->reply("You can only change raid ranks up to and including {$nameSettableAL}.");
			return false;
		}
		return true;
	}

	public function add(string $who, string $sender, CommandReply $sendto, int $rank, string $rankName, string $alName): bool {
		if ($this->chatBot->get_uid($who) == null) {
			$sendto->reply("Character <highlight>$who<end> does not exist.");
			return false;
		}

		if ($this->checkExisting($who, $rank)) {
			$sendto->reply(
				"<highlight>$who<end> is already $rankName. ".
				"To promote/demote to a different rank, add the ".
				"rank number (1, 2 or 3) to the command."
			);
			return false;
		}

		if (!$this->canChangeRaidRank($sender, $who, $alName, $sendto)) {
			return false;
		}

		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main !== $who) {
			$msg = "<red>WARNING<end>: $who is not a main. This command did NOT affect $who's access level and no action was performed.";
			$sendto->reply($msg);
			return false;
		}

		$action = $this->addToLists($who, $sender, $rank);

		$sendto->reply(
			"<highlight>{$who}<end> has been <highlight>{$action}<end> ".
			"to {$rankName}."
		);
		$this->chatBot->sendTell(
			"You have been <highlight>{$action}<end> to {$rankName} ".
			"by <highlight>$sender<end>.",
			$who
		);
		return true;
	}

	public function remove(string $who, string $sender, CommandReply $sendto, array $ranks, string $rankName): bool {
		if (!in_array($this->ranks[$who]->rank ?? null, $ranks)) {
			$sendto->reply("<highlight>$who<end> is not $rankName.");
			return false;
		}

		if (!$this->canChangeRaidRank($sender, $who, null, $sendto)) {
			return false;
		}

		$this->removeFromLists($who, $sender);

		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main !== $who) {
			$msg = "<red>WARNING<end>: $who is not a main.  This command did NOT affect $who's access level.";
			$sendto->reply($msg);
		}

		$sendto->reply("<highlight>$who<end> has been removed as $rankName.");
		$this->chatBot->sendTell("You have been removed as $rankName by <highlight>$sender<end>.", $who);
		return true;
	}

	/**
	 * @HandlesCommand("raidadmin")
	 * @Mask $action (add|promote)
	 */
	public function raidAdminAddCommand(CmdContext $context, string $action, PCharacter $char, ?int $rank): void {
		$rank ??= 1;
		if ($rank < 1 || $rank > 3) {
			$context->reply("The admin rank must be a number between 1 and 3");
			return;
		}
		$rankName = $this->settingManager->getString("name_raid_admin_$rank")??"";

		$this->add($char(), $context->char->name, $context, $rank+6, $rankName, "raid_admin_$rank");
	}

	/**
	 * @HandlesCommand("raidadmin")
	 * @Mask $action (remove|rem|del|rm|demote)
	 */
	public function raidAdminRemoveCommand(CmdContext $context, string $action, PCharacter $char): void {
		$rank = 'a raid admin';

		$this->remove($char(), $context->char->name, $context, [7, 8, 9], $rank);
	}

	/**
	 * @HandlesCommand("raidleader")
	 * @Mask $action (add|promote)
	 */
	public function raidLeaderAddCommand(CmdContext $context, string $action, PCharacter $char, ?int $rank): void {
		$rank ??= 1;
		if ($rank < 1 || $rank > 3) {
			$context->reply("The leader rank must be a number between 1 and 3");
			return;
		}
		$rankName = $this->settingManager->getString("name_raid_leader_$rank")??"";

		$this->add($char(), $context->char->name, $context, $rank+3, $rankName, "raid_leader_$rank");
	}

	/**
	 * @HandlesCommand("raidleader")
	 * @Mask $action (rem|del|rm|demote)
	 */
	public function raidLeaderRemoveCommand(CmdContext $context, string $action, PCharacter $char): void {
		$rank = 'a raid leader';

		$this->remove($char(), $context->char->name, $context, [4, 5, 6], $rank);
	}

	protected function getRaidsByStarter(): Collection {
		$query = $this->db->table(RaidController::DB_TABLE, "r")
			->join(RaidMemberController::DB_TABLE . " AS rm", "r.raid_id", "rm.raid_id")
			->leftJoin("alts AS a", "r.started_by", "a.alt")
			->groupBy("r.raid_id", "r.started_by", "r.started");
		$query = $query->havingRaw("COUNT(*) >= 5")
			->select(
				"r.raid_id",
				"r.started",
				$query->colFunc("COALESCE", ["a.main", "r.started_by"], "main"),
				$query->colFunc("COUNT", "*", "count")
			);
		return $query->asObj();
	}

	protected function renderLeaders(bool $showStats, bool $showOfflineAlts, Collection $stats, string ...$names): string {
		sort($names);
		$output = [];
		$raids = $stats->groupBy("main");
		foreach ($names as $who) {
			$line = "<tab>{$who}" . $this->getOnlineStatus($who);
			if ($showStats) {
				$myRaids = $raids->get($who, new Collection());
				$numRaids = $myRaids->count();
				$recentlyDuration = $this->settingManager->getInt('raid_duration_recently');
				if ($recentlyDuration > 0) {
					$numRaidsRecently = $myRaids->where("started", ">", time() - $recentlyDuration)->count();
				}
				$line .= " (Raids started: {$numRaids}";
				if (isset($numRaidsRecently)) {
					$line .= " / {$numRaidsRecently}";
				}
				$line .= ")";
			}
			$line .= "\n".
				$this->getAltLeaderInfo($who, $showOfflineAlts);
			$output []= $line;
		}
		return join("", $output) . "\n";
	}

	/**
	 * @HandlesCommand("leaderlist")
	 * @Mask $all all
	 */
	public function leaderlistCommand(CmdContext $context, ?string $all): void {
		$showOfflineAlts = isset($all);

		$blob = "";
		$admins = array_filter(
			$this->ranks,
			function (RaidRank $rank): bool {
				return $rank->rank >= 7 && $rank->name !== "";
			}
		);
		$leaders = array_filter(
			$this->ranks,
			function (RaidRank $rank): bool {
				return $rank->rank < 7 && $rank->rank >= 4 && $rank->name !== "";
			}
		);

		if (empty($leaders) && empty($admins)) {
			$context->reply("<myname> has no raid leaders or raid admins.");
			return;
		}

		$raidStats = $this->getRaidsByStarter();
		if (count($admins)) {
			$blob .= "<header2>Raid admins<end>\n".
				$this->renderLeaders(
					$this->accessManager->checkSingleAccess($context->char->name, "raid_leader_2"),
					$showOfflineAlts,
					$raidStats,
					...array_keys($admins)
				);
		}

		if (count($leaders)) {
			$blob .= "<header2>Raid leaders<end>\n".
				$this->renderLeaders(
					$this->accessManager->checkSingleAccess($context->char->name, "raid_admin_2"),
					$showOfflineAlts,
					$raidStats,
					...array_keys($leaders)
				);
		}

		$link = $this->text->makeBlob('Raid leaders/admins', $blob);
		$context->reply($link);
	}

	/**
	 * Get the string of the online status
	 * @param string $who Playername
	 * @return string " (<green>online<end>)" and so on
	 */
	private function getOnlineStatus(string $who): string {
		if ($this->buddylistManager->isOnline($who) && isset($this->chatBot->chatlist[$who])) {
			return " (<green>Online and in chat<end>)";
		} elseif ($this->buddylistManager->isOnline($who)) {
			return " (<green>Online<end>)";
		} else {
			return " (<red>Offline<end>)";
		}
	}

	private function getAltLeaderInfo(string $who, bool $showOfflineAlts): string {
		$blob = '';
		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main === $who) {
			$alts = $altInfo->getAllValidatedAlts();
			sort($alts);
			foreach ($alts as $alt) {
				if ($showOfflineAlts || $this->buddylistManager->isOnline($alt)) {
					$blob .= "<tab><tab>$alt" . $this->getOnlineStatus($alt) . "\n";
				}
			}
		}
		return $blob;
	}

	/**
	 * @Event("alt(newmain)")
	 * @Description("Move raid rank to new main")
	 */
	public function moveRaidRanks(AltEvent $event): void {
		$oldRank = $this->ranks[$event->alt] ?? null;
		if ($oldRank === null) {
			return;
		}
		$this->removeFromLists($event->alt, $event->main);
		$this->addToLists($event->main, $event->alt, $oldRank->rank);
		$this->logger->log('INFO', "Moved raid rank {$oldRank->rank} from {$event->alt} to {$event->main}.");
	}
}
