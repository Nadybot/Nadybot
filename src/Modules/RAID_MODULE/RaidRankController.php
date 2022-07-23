<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use function Amp\call;
use function Amp\Promise\rethrow;

use Amp\Promise;
use Generator;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessLevelProvider,
	AccessManager,
	AdminManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	CommandAlias,
	CommandReply,
	DB,
	DBSchema\Audit,
	LoggerWrapper,
	ModuleInstance,
	Modules\ADMIN\AdminController,
	Modules\ALTS\AltEvent,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PCharacter,
	SettingManager,
	Text,
};

#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Ranks"),
	NCA\DefineCommand(
		command: "raidadmin",
		accessLevel: "raid_admin_2",
		description: "Promote/demote someone to/from raid admin",
	),
	NCA\DefineCommand(
		command: "raidleader",
		accessLevel: "raid_admin_1",
		description: "Promote/demote someone to/from raid leader",
	),
	NCA\DefineCommand(
		command: "leaderlist",
		accessLevel: "all",
		description: "Shows the list of raid leaders and admins",
		defaultStatus: 1,
		alias: "leaders"
	)
]
class RaidRankController extends ModuleInstance implements AccessLevelProvider {
	public const DB_TABLE = "raid_rank_<myname>";

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AdminManager $adminManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public AdminController $adminController;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Number of raid ranks below your own you can manage */
	#[NCA\Setting\Number]
	public int $raidRankPromotionDistance = 1;

	/** Name of the raid leader rank 1 */
	#[NCA\Setting\Text]
	public string $nameRaidLeader1 = "Apprentice Leader";

	/** Name of the raid leader rank 2 */
	#[NCA\Setting\Text]
	public string $nameRaidLeader2 = "Leader";

	/** Name of the raid leader rank 3 */
	#[NCA\Setting\Text]
	public string $nameRaidLeader3 = "Veteran Leader";

	/** Name of the raid admin rank 1 */
	#[NCA\Setting\Text]
	public string $nameRaidAdmin1 = "Apprentice Raid Admin";

	/** Name of the raid admin rank 2 */
	#[NCA\Setting\Text]
	public string $nameRaidAdmin2 = "Raid Admin";

	/** Name of the raid admin rank 3 */
	#[NCA\Setting\Text]
	public string $nameRaidAdmin3 = "Veteran Raid Admin";

	/** Duration considered "recent" in raid stats for leaders command */
	#[NCA\Setting\Options(options: [
		'Off' => 0,
		'1 Month' => 2592000,
		'3 Months' => 7776000,
		'6 Months' => 15552000,
		'1 Year' => 31536000,
	])]
	public int $raidDurationRecently = 2592000;

	/** Include admins in leaderlist */
	#[NCA\Setting\Boolean]
	public bool $leadersIncludeAdmins = false;

	/** @var array<string,RaidRank> */
	public array $ranks = [];

	/** @todo: Add support for the raid levels */
	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);

		$this->commandAlias->register($this->moduleName, "raidadmin", "raid admin");
		$this->commandAlias->register($this->moduleName, "raidleader", "raid leader");
	}

	public function getSingleAccessLevel(string $sender): ?string {
		if (!isset($this->ranks[$sender])) {
			return null;
		}
		$rank = $this->ranks[$sender]->rank;
		if ($rank >= 7) {
			return "raid_admin_" . ($rank-6);
		}
		if ($rank >= 4) {
			return "raid_leader_" . ($rank-3);
		}
		return "raid_level_{$rank}";
	}

	#[NCA\Event(
		name: "connect",
		description: "Add raid leader and admins to the buddy list",
		defaultStatus: 1
	)]
	public function checkRaidRanksEvent(): Generator {
		yield $this->db->table(self::DB_TABLE)
			->asObj(RaidRank::class)
			->map(function (RaidRank $row): Promise {
				return $this->buddylistManager->addAsync($row->name, 'raidrank');
			})->toArray();
	}

	/** Load the raid leaders, admins and veterans from the database into $ranks */
	#[NCA\Setup]
	public function uploadRaidRanks(): void {
		$this->db->table(self::DB_TABLE)
			->asObj(RaidRank::class)
			->each(function (RaidRank $row): void {
				$this->ranks[$row->name] = $row;
			});
	}

	/** Demote someone's special raid rank */
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
		rethrow($this->buddylistManager->addAsync($who, 'raidrank'));

		$audit = new Audit();
		$audit->actor = $sender;
		$audit->actee = $who;
		$audit->action = AccessManager::ADD_RANK;
		$audit->value = (string)($this->accessManager->getAccessLevels()["raid_leader_1"] - ($rank-4));
		$this->accessManager->addAudit($audit);

		return $action;
	}

	/** Check if a user $who has raid rank $rank */
	public function checkExisting(string $who, int $rank): bool {
		return ($this->ranks[$who]->rank??-1) === $rank;
	}

	/** Chheck if $actor's access level is higher than $actee's */
	public function checkAccessLevel(string $actor, string $actee): bool {
		$senderAccessLevel = $this->accessManager->getAccessLevelForCharacter($actor);
		$whoAccessLevel = $this->accessManager->getSingleAccessLevel($actee);
		return $this->accessManager->compareAccessLevels($whoAccessLevel, $senderAccessLevel) < 0;
	}

	/** Check if $sender can change $who's raid rank (to $newRank or in general) */
	public function canChangeRaidRank(string $sender, string $who, ?string $newRank, CommandReply $sendto): bool {
		if (!$this->checkAccessLevel($sender, $who)) {
			$sendto->reply("You must have a higher access level than <highlight>{$who}<end> in order to change their access level.");
			return false;
		}
		$reqDistance = $this->raidRankPromotionDistance;
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

	/** @param int[] $ranks */
	public function remove(string $who, string $sender, CommandReply $sendto, array $ranks, string $rankName): bool {
		if (!in_array($this->ranks[$who]->rank ?? null, $ranks)) {
			$sendto->reply("<highlight>{$who}<end> is not {$rankName}.");
			return false;
		}

		if (!$this->canChangeRaidRank($sender, $who, null, $sendto)) {
			return false;
		}

		$this->removeFromLists($who, $sender);

		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main !== $who) {
			$msg = "<red>WARNING<end>: {$who} is not a main.  This command did NOT affect {$who}'s access level.";
			$sendto->reply($msg);
		}

		$sendto->reply("<highlight>{$who}<end> has been removed as {$rankName}.");
		$this->chatBot->sendTell("You have been removed as {$rankName} by <highlight>{$sender}<end>.", $who);
		return true;
	}

	/** Promote someone to raid admin */
	#[NCA\HandlesCommand("raidadmin")]
	#[NCA\Help\Group("raid-ranks")]
	public function raidAdminAddCommand(
		CmdContext $context,
		#[NCA\Str("add", "promote")] string $action,
		PCharacter $char,
		?int $rank
	): Generator {
		$rank ??= 1;
		if ($rank < 1 || $rank > 3) {
			$context->reply("The admin rank must be a number between 1 and 3");
			return;
		}
		$rankName = $this->settingManager->getString("name_raid_admin_{$rank}")??"";

		yield $this->add($char(), $context->char->name, $context, $rank+6, $rankName, "raid_admin_{$rank}");
	}

	/** Demote someone from raid admin */
	#[NCA\HandlesCommand("raidadmin")]
	#[NCA\Help\Group("raid-ranks")]
	public function raidAdminRemoveCommand(
		CmdContext $context,
		#[NCA\Str("remove", "rem", "del", "rm", "demote")] string $action,
		PCharacter $char
	): void {
		$rank = 'a raid admin';

		$this->remove($char(), $context->char->name, $context, [7, 8, 9], $rank);
	}

	/** Promote someone to raid leader */
	#[NCA\HandlesCommand("raidleader")]
	#[NCA\Help\Group("raid-ranks")]
	public function raidLeaderAddCommand(
		CmdContext $context,
		#[NCA\Str("add", "promote")] string $action,
		PCharacter $char,
		?int $rank
	): Generator {
		$rank ??= 1;
		if ($rank < 1 || $rank > 3) {
			$context->reply("The leader rank must be a number between 1 and 3");
			return;
		}
		$rankName = $this->settingManager->getString("name_raid_leader_{$rank}")??"";

		yield $this->add($char(), $context->char->name, $context, $rank+3, $rankName, "raid_leader_{$rank}");
	}

	/** Demote someone from raid leader */
	#[NCA\HandlesCommand("raidleader")]
	#[NCA\Help\Group("raid-ranks")]
	public function raidLeaderRemoveCommand(
		CmdContext $context,
		#[NCA\Str("rem", "del", "rm", "demote")] string $action,
		PCharacter $char
	): void {
		$rank = 'a raid leader';

		$this->remove($char(), $context->char->name, $context, [4, 5, 6], $rank);
	}

	/** See the list of raid leaders/admins, 'all' to include all offline alts */
	#[NCA\HandlesCommand("leaderlist")]
	public function leaderlistCommand(CmdContext $context, #[NCA\Str("all")] ?string $all): void {
		$showOfflineAlts = isset($all);
		$adminLines = [];
		if ($this->leadersIncludeAdmins) {
			$adminLines = $this->adminController->getLeaderList($showOfflineAlts);
		}

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

		if (empty($leaders) && empty($admins) && empty($adminLines)) {
			$context->reply("<myname> has no raid leaders or raid admins.");
			return;
		}

		if (count($adminLines)) {
			$blob .= join("\n", $adminLines) . "\n";
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

		$title = 'Raid leaders/admins';
		if (count($adminLines)) {
			$title = "All leaders and admins";
		}
		$link = $this->text->makeBlob($title, $blob);
		$context->reply($link);
	}

	#[NCA\Event(
		name: "alt(newmain)",
		description: "Move raid rank to new main"
	)]
	public function moveRaidRanks(AltEvent $event): void {
		$oldRank = $this->ranks[$event->alt] ?? null;
		if ($oldRank === null) {
			return;
		}
		$this->removeFromLists($event->alt, $event->main);
		$this->addToLists($event->main, $event->alt, $oldRank->rank);
		$this->logger->notice("Moved raid rank {$oldRank->rank} from {$event->alt} to {$event->main}.");
	}

	/** @return Collection<RaidStat> */
	protected function getRaidsByStarter(): Collection {
		$query = $this->db->table(RaidController::DB_TABLE, "r")
			->join(RaidMemberController::DB_TABLE . " AS rm", "r.raid_id", "rm.raid_id")
			->groupBy("r.raid_id", "r.started_by", "r.started");
		return $query->havingRaw("COUNT(*) >= 5")
			->select(
				"r.raid_id",
				"r.started",
				"r.started_by",
				$query->colFunc("COUNT", "*", "num_raiders")
			)
			->asObj(RaidStat::class)
			->each(function (RaidStat $stat): void {
				$stat->starter_main = $this->altsController->getMainOf($stat->started_by);
			});
	}

	/** @param Collection<RaidStat> $stats */
	protected function renderLeaders(bool $showStats, bool $showOfflineAlts, Collection $stats, string ...$names): string {
		sort($names);
		$output = [];
		$raids = $stats->groupBy("starter_main");
		foreach ($names as $who) {
			$line = "<tab>{$who}" . $this->getOnlineStatus($who);
			if ($showStats) {
				$myRaids = $raids->get($who, new Collection());
				$numRaids = $myRaids->count();
				$recentlyDuration = $this->raidDurationRecently;
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

	/** @return Promise<bool> */
	private function add(string $who, string $sender, CommandReply $sendto, int $rank, string $rankName, string $alName): Promise {
		return call(function () use ($who, $sender, $sendto, $rank, $rankName, $alName): Generator {
			if (null === yield $this->chatBot->getUid2($who)) {
				$sendto->reply("Character <highlight>{$who}<end> does not exist.");
				return false;
			}

			if ($this->checkExisting($who, $rank)) {
				$sendto->reply(
					"<highlight>{$who}<end> is already {$rankName}. ".
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
				$msg = "<red>WARNING<end>: {$who} is not a main. This command did NOT affect {$who}'s access level and no action was performed.";
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
				"by <highlight>{$sender}<end>.",
				$who
			);
			return true;
		});
	}

	/**
	 * Get the string of the online status
	 *
	 * @param string $who Playername
	 *
	 * @return string " (<on>online<end>)" and so on
	 */
	private function getOnlineStatus(string $who): string {
		if ($this->buddylistManager->isOnline($who) && isset($this->chatBot->chatlist[$who])) {
			return " (<on>Online and in chat<end>)";
		} elseif ($this->buddylistManager->isOnline($who)) {
			return " (<on>Online<end>)";
		}
		return " (<off>Offline<end>)";
	}

	private function getAltLeaderInfo(string $who, bool $showOfflineAlts): string {
		$blob = '';
		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main === $who) {
			$alts = $altInfo->getAllValidatedAlts();
			sort($alts);
			foreach ($alts as $alt) {
				if ($showOfflineAlts || $this->buddylistManager->isOnline($alt)) {
					$blob .= "<tab><tab>{$alt}" . $this->getOnlineStatus($alt) . "\n";
				}
			}
		}
		return $blob;
	}
}
