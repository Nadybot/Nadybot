<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use function Amp\async;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	Attributes\Parameter\Str,
	AuditAction,
	BuddylistManager,
	CmdContext,
	Collection,
	CommandAlias,
	DB,
	DBSchema\Audit,
	Events\ConnectEvent,
	ModuleInstance,
	Modules\ADMIN\AdminController,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PCharacter,
	SettingManager,
	Text,
	Types\AccessLevel,
	Types\AccessLevelProvider,
	Types\CommandReply,
	Types\Status,
};
use Nadybot\Core\Modules\ALTS\AltNewMainEvent;
use Psr\Log\LoggerInterface;

#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Ranks'),
	NCA\DefineCommand(
		command: 'raidadmin',
		accessLevel: AccessLevel::RaidAdmin2,
		description: 'Promote/demote someone to/from raid admin',
	),
	NCA\DefineCommand(
		command: 'raidleader',
		accessLevel: AccessLevel::RaidAdmin1,
		description: 'Promote/demote someone to/from raid leader',
	),
	NCA\DefineCommand(
		command: 'leaderlist',
		accessLevel: AccessLevel::All,
		description: 'Shows the list of raid leaders and admins',
		defaultStatus: Status::Enabled,
		alias: 'leaders'
	)
]
class RaidRankController extends ModuleInstance implements AccessLevelProvider {
	/** Number of raid ranks below your own you can manage */
	#[NCA\Setting\Number]
	public int $raidRankPromotionDistance = 1;

	/** Name of the raid leader rank 1 */
	#[NCA\Setting\Text]
	public string $nameRaidLeader1 = 'Apprentice Leader';

	/** Name of the raid leader rank 2 */
	#[NCA\Setting\Text]
	public string $nameRaidLeader2 = 'Leader';

	/** Name of the raid leader rank 3 */
	#[NCA\Setting\Text]
	public string $nameRaidLeader3 = 'Veteran Leader';

	/** Name of the raid admin rank 1 */
	#[NCA\Setting\Text]
	public string $nameRaidAdmin1 = 'Apprentice Raid Admin';

	/** Name of the raid admin rank 2 */
	#[NCA\Setting\Text]
	public string $nameRaidAdmin2 = 'Raid Admin';

	/** Name of the raid admin rank 3 */
	#[NCA\Setting\Text]
	public string $nameRaidAdmin3 = 'Veteran Raid Admin';

	/** Duration considered "recent" in raid stats for leaders command */
	#[NCA\Setting\Options(options: [
		'Off' => 0,
		'1 Month' => 2_592_000,
		'3 Months' => 7_776_000,
		'6 Months' => 15_552_000,
		'1 Year' => 31_536_000,
	])]
	public int $raidDurationRecently = 2_592_000;

	/** Include admins in leaderlist */
	#[NCA\Setting\Boolean]
	public bool $leadersIncludeAdmins = false;

	/** Include SuperAdmins when including admins in leaderlist */
	#[NCA\Setting\Boolean]
	public bool $leadersIncludeSuperAdmins = false;

	/** @var array<string,RaidRank> */
	public array $ranks = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private AdminController $adminController;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private DB $db;

	/** @TODO: Add support for the raid levels */
	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);

		$this->commandAlias->register($this->moduleName, 'raidadmin', 'raid admin');
		$this->commandAlias->register($this->moduleName, 'raidleader', 'raid leader');
	}

	public function getSingleAccessLevel(string $sender): ?AccessLevel {
		if (!isset($this->ranks[$sender])) {
			return null;
		}
		$rank = $this->ranks[$sender]->rank;
		return match ($rank) {
			9 => AccessLevel::RaidAdmin3,
			8 => AccessLevel::RaidAdmin2,
			7 => AccessLevel::RaidAdmin2,
			6 => AccessLevel::RaidLeader3,
			5 => AccessLevel::RaidLeader2,
			4 => AccessLevel::RaidLeader1,
			default => throw new \Error("Invalid rank: {$rank}"),
		};
	}

	/** Add raid leader and admins to the buddy list */
	#[NCA\HandlesEvent(defaultStatus: Status::Enabled)]
	public function checkRaidRanksEvent(ConnectEvent $event): void {
		$this->db->table(RaidRank::getTable())
			->asObj(RaidRank::class)
			->each(function (RaidRank $row): void {
				$this->buddylistManager->addName($row->name, 'raidrank');
			});
	}

	/** Load the raid leaders, admins and veterans from the database into $ranks */
	#[NCA\Setup]
	public function uploadRaidRanks(): void {
		$this->db->table(RaidRank::getTable())
			->asObj(RaidRank::class)
			->each(function (RaidRank $row): void {
				$this->ranks[$row->name] = $row;
			});
	}

	/** Demote someone's special raid rank */
	public function removeFromLists(string $who, string $sender): void {
		$oldRank = $this->ranks[$who]??null;
		unset($this->ranks[$who]);
		$this->db->table(RaidRank::getTable())
			->where('name', $who)
			->delete();
		$this->buddylistManager->remove($who, 'raidrank');
		if (isset($oldRank)) {
			$audit = new Audit(
				actor: $sender,
				actee: $who,
				action: AuditAction::DelRank,
				value: (string)(AccessLevel::RaidLeader1->toInt() - ($oldRank->rank-4)),
			);
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
			$action = 'demoted';
		}
		$this->db->upsert(new RaidRank(name: $who, rank: $rank));

		if (isset($oldRank)) {
			$audit = new Audit(
				actor: $sender,
				actee: $who,
				action: AuditAction::DelRank,
				value: (string)(AccessLevel::RaidLeader1->toInt() - ($oldRank->rank-4)),
			);
			$this->accessManager->addAudit($audit);
		}

		$this->ranks[$who] = new RaidRank(rank: $rank, name: $who);
		async($this->buddylistManager->addName(...), $who, 'raidrank')->ignore();

		$audit = new Audit(
			actor: $sender,
			actee: $who,
			action: AuditAction::AddRank,
			value: (string)(AccessLevel::RaidLeader1->toInt() - ($rank-4)),
		);
		$this->accessManager->addAudit($audit);

		return $action;
	}

	/** Check if a user $who has raid rank $rank */
	public function checkExisting(string $who, int $rank): bool {
		return ($this->ranks[$who]->rank??-1) === $rank;
	}

	/** Check if $actor's access level is higher than $actee's */
	public function checkAccessLevel(string $actor, string $actee): bool {
		$actorAccessLevel = $this->accessManager->getAccessLevelForCharacter($actor);
		$acteeAccessLevel = $this->accessManager->getSingleAccessLevel($actee);
		return $actorAccessLevel->higherThan($acteeAccessLevel);
	}

	/** Check if $sender can change $who's raid rank (to $newRank or in general) */
	public function canChangeRaidRank(string $sender, string $who, ?string $newRank, CommandReply $sendto): bool {
		if (!$this->checkAccessLevel($sender, $who)) {
			$sendto->reply("You must have a higher access level than <highlight>{$who}<end> in order to change their access level.");
			return false;
		}
		$reqDistance = $this->raidRankPromotionDistance;
		$senderAccessLevel = $this->accessManager->getAccessLevelForCharacter($sender);
		$oldAccessLevel = $this->accessManager->getAccessLevelForCharacter($who);
		$newAccessLevel = $oldAccessLevel;
		$numSenderAccessLevel = $senderAccessLevel->toInt();
		$numOldAccessLevel = $oldAccessLevel->toInt();
		$numSettableAL = $numSenderAccessLevel + $reqDistance;
		$numNewAccessLevel = $newAccessLevel->toInt();
		if ($numNewAccessLevel < $numSettableAL || $numOldAccessLevel < $numSettableAL) {
			$maxSettableAL = AccessLevel::fromInt($numSettableAL);
			$sendto->reply("You can only change raid ranks up to and including {$maxSettableAL->displayName()}.");
			return false;
		}
		return true;
	}

	/** @param list<int> $ranks */
	public function remove(string $who, string $sender, CommandReply $sendto, array $ranks, string $rankName): bool {
		if (!in_array($this->ranks[$who]->rank ?? null, $ranks, true)) {
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
	#[NCA\HandlesCommand('raidadmin')]
	#[NCA\Help\Group('raid-ranks')]
	public function raidAdminAddCommand(
		CmdContext $context,
		#[Str('add', 'promote')] string $action,
		PCharacter $char,
		?int $rank
	): void {
		$rank ??= 1;
		if ($rank < 1 || $rank > 3) {
			$context->reply('The admin rank must be a number between 1 and 3');
			return;
		}
		$rankName = $this->settingManager->getString("name_raid_admin_{$rank}")??'';

		$this->add($char(), $context->char->name, $context, $rank+6, $rankName, "raid_admin_{$rank}");
	}

	/** Demote someone from raid admin */
	#[NCA\HandlesCommand('raidadmin')]
	#[NCA\Help\Group('raid-ranks')]
	public function raidAdminRemoveCommand(
		CmdContext $context,
		#[Str('remove', 'rem', 'del', 'rm', 'demote')] string $action,
		PCharacter $char
	): void {
		$rank = 'a raid admin';

		$this->remove($char(), $context->char->name, $context, [7, 8, 9], $rank);
	}

	/** Promote someone to raid leader */
	#[NCA\HandlesCommand('raidleader')]
	#[NCA\Help\Group('raid-ranks')]
	public function raidLeaderAddCommand(
		CmdContext $context,
		#[Str('add', 'promote')] string $action,
		PCharacter $char,
		?int $rank
	): void {
		$rank ??= 1;
		if ($rank < 1 || $rank > 3) {
			$context->reply('The leader rank must be a number between 1 and 3');
			return;
		}
		$rankName = $this->settingManager->getString("name_raid_leader_{$rank}")??'';

		$this->add($char(), $context->char->name, $context, $rank+3, $rankName, "raid_leader_{$rank}");
	}

	/** Demote someone from raid leader */
	#[NCA\HandlesCommand('raidleader')]
	#[NCA\Help\Group('raid-ranks')]
	public function raidLeaderRemoveCommand(
		CmdContext $context,
		#[Str('rem', 'del', 'rm', 'demote')] string $action,
		PCharacter $char
	): void {
		$rank = 'a raid leader';

		$this->remove($char(), $context->char->name, $context, [4, 5, 6], $rank);
	}

	/** See the list of raid leaders/admins, 'all' to include all offline alts */
	#[NCA\HandlesCommand('leaderlist')]
	public function leaderlistCommand(CmdContext $context, #[Str('all')] ?string $all): void {
		$showOfflineAlts = isset($all);
		$adminLines = [];
		if ($this->leadersIncludeAdmins) {
			$adminLines = $this->adminController->getLeaderList($showOfflineAlts, $this->leadersIncludeSuperAdmins);
		}

		$blob = '';
		$admins = array_filter(
			$this->ranks,
			static function (RaidRank $rank): bool {
				return $rank->rank >= 7 && $rank->name !== '';
			}
		);
		$leaders = array_filter(
			$this->ranks,
			static function (RaidRank $rank): bool {
				return $rank->rank < 7 && $rank->rank >= 4 && $rank->name !== '';
			}
		);

		if (!count($leaders) && !count($admins) && !count($adminLines)) {
			$context->reply('<myname> has no raid leaders or raid admins.');
			return;
		}

		if (count($adminLines)) {
			$blob .= implode("\n", $adminLines) . "\n";
		}

		$raidStats = $this->getRaidsByStarter();
		if (count($admins)) {
			$blob .= "<header2>Raid admins<end>\n".
				$this->renderLeaders(
					$this->accessManager->checkSingleAccess($context->char->name, AccessLevel::RaidLeader2),
					$showOfflineAlts,
					$raidStats,
					...array_keys($admins)
				);
		}

		if (count($leaders)) {
			$blob .= "<header2>Raid leaders<end>\n".
				$this->renderLeaders(
					$this->accessManager->checkSingleAccess($context->char->name, AccessLevel::RaidAdmin2),
					$showOfflineAlts,
					$raidStats,
					...array_keys($leaders)
				);
		}

		$title = 'Raid leaders/admins';
		if (count($adminLines)) {
			$title = 'All leaders and admins';
		}
		$link = Text::makeBlob($title, $blob);
		$context->reply($link);
	}

	/** Move raid rank to new main */
	#[NCA\HandlesEvent]
	public function moveRaidRanks(AltNewMainEvent $event): void {
		$oldRank = $this->ranks[$event->alt] ?? null;
		if ($oldRank === null) {
			return;
		}
		$this->removeFromLists($event->alt, $event->main);
		$this->addToLists($event->main, $event->alt, $oldRank->rank);
		$this->logger->notice('Moved raid rank {rank} from {alt} to {main}.', [
			'rank' => $oldRank->rank,
			'alt' => $event->alt,
			'main' => $event->main,
		]);
	}

	/** @return Collection<int,RaidStat> */
	protected function getRaidsByStarter(): Collection {
		$query = $this->db->table(Raid::getTable(), 'r')
			->join(RaidMember::getTable() . ' AS rm', 'r.raid_id', 'rm.raid_id')
			->groupBy('r.raid_id', 'r.started_by', 'r.started');
		return $query->havingRaw('COUNT(*) >= 5')
			->select([
				'r.raid_id',
				'r.started',
				'r.started_by',
				$query->raw($query->colFunc('COUNT', '*', 'num_raiders')),
			])
			->asObj(RaidStat::class)
			->each(function (RaidStat $stat): void {
				$stat->starter_main = $this->altsController->getMainOf($stat->started_by);
			});
	}

	/** @param Collection<int,RaidStat> $stats */
	protected function renderLeaders(bool $showStats, bool $showOfflineAlts, Collection $stats, string ...$names): string {
		sort($names);
		$output = [];

		/** @var Collection<string,Collection<int,RaidStat>> */
		$raids = $stats->groupByString('starter_main');
		foreach ($names as $who) {
			$line = "<tab>{$who}" . $this->getOnlineStatus($who);
			if ($showStats) {
				$myRaids = $raids->get($who, new Collection());
				$numRaids = $myRaids->count();
				$recentlyDuration = $this->raidDurationRecently;
				if ($recentlyDuration > 0) {
					$numRaidsRecently = $myRaids->where('started', '>', time() - $recentlyDuration)->count();
				}
				$line .= " (Raids started: {$numRaids}";
				if (isset($numRaidsRecently)) {
					$line .= " / {$numRaidsRecently}";
				}
				$line .= ')';
			}
			$line .= "\n".
				$this->getAltLeaderInfo($who, $showOfflineAlts);
			$output []= $line;
		}
		return implode('', $output) . "\n";
	}

	private function add(string $who, string $sender, CommandReply $sendto, int $rank, string $rankName, string $alName): bool {
		if (null === $this->chatBot->getUid($who)) {
			$sendto->reply("Character <highlight>{$who}<end> does not exist.");
			return false;
		}

		if ($this->checkExisting($who, $rank)) {
			$sendto->reply(
				"<highlight>{$who}<end> is already {$rankName}. ".
				'To promote/demote to a different rank, add the '.
				'rank number (1, 2 or 3) to the command.'
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
	}

	/**
	 * Get the string of the online status
	 *
	 * @param string $who Name of the character
	 *
	 * @return string " (<on>online<end>)" and so on
	 */
	private function getOnlineStatus(string $who): string {
		if ($this->buddylistManager->isOnline($who) === true && $this->chatBot->inChatlist($who)) {
			return ' (<on>Online and in chat<end>)';
		} elseif ($this->buddylistManager->isOnline($who)) {
			return ' (<on>Online<end>)';
		}
		return ' (<off>Offline<end>)';
	}

	private function getAltLeaderInfo(string $who, bool $showOfflineAlts): string {
		$blob = '';
		$altInfo = $this->altsController->getAltInfo($who);
		if ($altInfo->main === $who) {
			$alts = $altInfo->getAllValidatedAlts();
			sort($alts);
			foreach ($alts as $alt) {
				if ($showOfflineAlts || $this->buddylistManager->isOnline($alt) === true) {
					$blob .= "<tab><tab>{$alt}" . $this->getOnlineStatus($alt) . "\n";
				}
			}
		}
		return $blob;
	}
}
