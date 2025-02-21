<?php declare(strict_types=1);

namespace Nadybot\Modules\PRIVATE_CHANNEL_MODULE;

use Exception;
use InvalidArgumentException;
use Nadybot\Core\DBSchema\{Admin, Member};
use Nadybot\Core\Modules\PREFERENCES\Preferences;
use Nadybot\Core\{
	AccessManager,
	AdminManager,
	Attributes as NCA,
	Config\BotConfig,
	DB,
	ExportCharacter,
	ModuleInstance,
	Nadybot,
	Types\AccessLevel,
	Types\ExporterInterface,
	Types\ImporterInterface
};
use Nadybot\Modules\GUILD_MODULE\OrgMember;
use Nadybot\Modules\MASSMSG_MODULE\MassMsgController;
use Nadybot\Modules\RAID_MODULE\{RaidRank, RaidRankController};
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('members'),
	NCA\Importer('members', ExportMember::class),
]
class MemberExporter extends ModuleInstance implements ExporterInterface, ImporterInterface {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private AdminManager $adminManager;

	#[NCA\Inject]
	private RaidRankController $raidRankController;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private Preferences $preferences;

	/** @return list<ExportMember> */
	public function export(DB $db, LoggerInterface $logger): array {
		$exported = [];

		/** @var list<ExportMember> */
		$result = [];

		$members = $db->table(Member::getTable())
			->asObj(Member::class);
		foreach ($members as $member) {
			$result []= new ExportMember(
				rank: 'member',
				character: new ExportCharacter(name: $member->name),
				autoInvite: (bool)$member->autoinv,
				joinedTime: $member->joined,
			);
			$exported[$member->name] = true;
		}

		$members = $db->table(RaidRank::getTable())
			->asObj(RaidRank::class);
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= new ExportMember(
				character: new ExportCharacter(name: $member->name),
				rank: 'member',
			);
			$exported[$member->name] = true;
		}
		$members = $db->table(OrgMember::getTable())
			->where('mode', '!=', 'del')
			->asObj(OrgMember::class);
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= new ExportMember(
				rank: 'member',
				character: new ExportCharacter(name: $member->name),
				autoInvite: false,
			);
			$exported[$member->name] = true;
		}

		$members = $db->table(Admin::getTable())
			->asObj(Admin::class);
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= new ExportMember(
				rank: 'member',
				character: new ExportCharacter(name: $member->name),
				autoInvite: false,
			);
			$exported[$member->name] = true;
		}
		foreach ($this->config->general->superAdmins as $superAdmin) {
			if (!isset($exported[$superAdmin])) {
				$result []= new ExportMember(
					character: new ExportCharacter(name: $superAdmin),
					autoInvite: false,
					rank: AccessLevel::Superadmin->value,
				);
			}
		}
		foreach ($result as &$datum) {
			assert(isset($datum->character->name), 'Every member of the bot must have a name');
			$datum->rank = $this->accessManager->getSingleAccessLevel($datum->character->name)->value;
			$logonMessage = $this->preferences->get($datum->character->name, 'logon_msg');
			$logoffMessage = $this->preferences->get($datum->character->name, 'logoff_msg');
			$massMessages = $this->preferences->get($datum->character->name, MassMsgController::PREF_MSGS);
			$massInvites = $this->preferences->get($datum->character->name, MassMsgController::PREF_INVITES);
			if (isset($logonMessage) && strlen($logonMessage)) {
				$datum->logonMessage ??= $logonMessage;
			}
			if (isset($logoffMessage) && strlen($logoffMessage)) {
				$datum->logoffMessage ??= $logoffMessage;
			}
			if (isset($massMessages) && strlen($massMessages)) {
				$datum->receiveMassMessages ??= $massMessages === 'on';
			}
			if (isset($massInvites) && strlen($massInvites)) {
				$datum->receiveMassInvites ??= $massInvites === 'on';
			}
		}
		return $result;
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$numImported = 0;
		$logger->notice('Importing {num_members} member(s)', [
			'num_members' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all members');
			$db->table(Member::getTable())->truncate();
			$db->table(OrgMember::getTable())->truncate();
			$db->table(Admin::getTable())->truncate();
			$db->table(RaidRank::getTable())->truncate();
			foreach ($data as $member) {
				if (!($member instanceof ExportMember)) {
					throw new InvalidArgumentException(__CLASS__ . '::' . __METHOD__ . '() called with wrong data');
				}

				/** @psalm-suppress PossiblyNullArgument */
				$id = $member->character->id ?? $this->chatBot->getUid($member->character->name);

				$name = $member->character->tryGetName();
				if (!isset($id) || !isset($name)) {
					continue;
				}
				$this->chatBot->cacheUidNameMapping(name: $name, uid: $id);
				$newRank = $rankMap[$member->rank] ?? null;
				if (!isset($newRank)) {
					throw new Exception("Cannot find rank {$member->rank} in the mapping");
				}
				$numImported++;
				if ($newRank->isRealRank()) {
					$db->insert(new Member(
						name: $name,
						autoinv: (int)($member->autoInvite ?? false),
						joined: $member->joinedTime ?? time(),
					));
				}
				if ($newRank->atLeast(AccessLevel::Mod)) {
					$adminLevel = ($newRank === AccessLevel::Mod) ? 3 : 4;
					$db->insert(new Admin(
						name: $name,
						adminlevel: $adminLevel,
					));
					$this->adminManager->setAdminLevel($name, $adminLevel);
				} elseif (($raidRank = $this->getRaidRank($newRank)) !== 0) {
					$db->insert(new Raidrank(
						name: $name,
						rank: $raidRank,
					));
				}
				if (isset($member->logonMessage)) {
					$this->preferences->save($name, 'logon_msg', $member->logonMessage);
				}
				if (isset($member->logoffMessage)) {
					$this->preferences->save($name, 'logoff_msg', $member->logoffMessage);
				}
				if (isset($member->receiveMassInvites)) {
					$this->preferences->save($name, MassMsgController::PREF_INVITES, $member->receiveMassInvites ? 'on' : 'off');
				}
				if (isset($member->receiveMassMessages)) {
					$this->preferences->save($name, MassMsgController::PREF_MSGS, $member->receiveMassMessages ? 'on' : 'off');
				}
			}
			$this->raidRankController->uploadRaidRanks();
		} catch (Throwable $e) {
			$logger->error('{error}. Rolling back changes.', [
				'error' => rtrim($e->getMessage(), '.'),
				'exception' => $e,
			]);
			$db->rollback();
			return;
		}
		$db->commit();
		$logger->notice('{num_imported} members successfully imported', [
			'num_imported' => $numImported,
		]);
	}

	private function getRaidRank(AccessLevel $al): int {
		switch ($al) {
			case AccessLevel::RaidLeader1:
				return 4;
			case AccessLevel::RaidLeader2:
				return 5;
			case AccessLevel::RaidLeader3:
				return 6;
			case AccessLevel::RaidAdmin1:
				return 7;
			case AccessLevel::RaidAdmin2:
				return 8;
			case AccessLevel::RaidAdmin3:
				return 9;
			default:
				return 0;
		}
	}
}
