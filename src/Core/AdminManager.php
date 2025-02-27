<?php declare(strict_types=1);

namespace Nadybot\Core;

use function Amp\async;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\Admin,
	DBSchema\Audit,
	Types\AccessLevel,
	Types\AccessLevelProvider,
	Types\RankChange,
};

/**
 * Manage the bot admins
 */
#[NCA\Instance]
class AdminManager implements AccessLevelProvider {
	/**
	 * Admin access levels of our admin users, keyed by character name
	 *
	 * @var array<string,int>
	 */
	private array $admins = [];

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private BotConfig $config;

	/**
	 * Get admin access levels of our admin users, keyed by character name
	 *
	 * @return array<string,int>
	 */
	public function getAdmins(): array {
		return $this->admins;
	}

	/** Get the admin access level of the given user, or `null` if not an admin/mod */
	public function getAdminLevel(string $user): ?int {
		return $this->admins[$user] ?? null;
	}

	/** Set the admin access level of the given user */
	public function setAdminLevel(string $user, int $level): void {
		$this->admins[$user] = $level;
	}

	/** Remove the given user from any mod/admin rank, except superadmin */
	public function delAdmin(string $user): void {
		unset($this->admins[$user]);
	}

	/** {@inheritDoc} */
	public function getSingleAccessLevel(string $sender): ?AccessLevel {
		$level = $this->getAdminLevel($sender) ?? 0;
		if ($level >= 4) {
			return AccessLevel::Admin;
		} elseif ($level >= 3) {
			return AccessLevel::Mod;
		}
		return null;
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);
	}

	/** Load the bot admins from the database into `$this->admins` */
	public function uploadAdmins(): void {
		foreach ($this->config->general->superAdmins as $superAdmin) {
			$this->db->table(Admin::getTable())->upsert(
				[
					'adminlevel' => 4,
					'name' => $superAdmin,
				],
				'name'
			);
		}

		$this->db->table(Admin::getTable())
			->asObj(Admin::class)
			->each(function (Admin $row): void {
				if (isset($row->adminlevel)) {
					$this->setAdminLevel($row->name, $row->adminlevel);
				}
			});
	}

	/**
	 * Demote someone from the admin position
	 *
	 * @param string $who    Who to demote
	 * @param string $sender Who requests demotion
	 */
	public function removeFromLists(string $who, string $sender): void {
		$oldRank = $this->getAdminLevel($who);
		$this->delAdmin($who);
		$this->db->table(Admin::getTable())->where('name', $who)->delete();
		$this->buddylistManager->remove($who, 'admin');
		$alMod = AccessLevel::Mod->toInt();
		if (!isset($oldRank)) {
			return;
		}
		$audit = new Audit(
			actor: $sender,
			actee: $who,
			action: AuditAction::DelRank,
			value: (string)($alMod - ($oldRank - $alMod)),
		);
		$this->accessManager->addAudit($audit);
	}

	/**
	 * Set the admin level of a user
	 *
	 * @param string $who      Whose admin level to change
	 * @param int    $intlevel Which admin level to set
	 * @param string $sender   Who requests to set the new level
	 *
	 * @return RankChange Either demotion or promotion
	 */
	public function addToLists(string $who, int $intlevel, string $sender): RankChange {
		$action = RankChange::Promotion;
		$alMod = AccessLevel::Mod->toInt();
		$adminLevel = $this->getAdminLevel($who);
		if (isset($adminLevel)) {
			$this->db->table(Admin::getTable())
				->where('name', $who)
				->update(['adminlevel' => $intlevel]);
			if ($adminLevel > $intlevel) {
				$action = RankChange::Demotion;
			}
			$audit = new Audit(
				actor: $sender,
				actee: $who,
				action: AuditAction::DelRank,
				value: (string)($alMod - ($adminLevel - $alMod)),
			);
			$this->accessManager->addAudit($audit);
		} else {
			$this->db->insert(new Admin(
				name: $who,
				adminlevel: $intlevel,
			));
		}

		$this->setAdminLevel($who, $intlevel);
		async($this->buddylistManager->addName(...), $who, 'admin')->ignore();

		$audit = new Audit(
			actor: $sender,
			actee: $who,
			action: AuditAction::AddRank,
			value: (string)($alMod - ($intlevel - $alMod)),
		);
		$this->accessManager->addAudit($audit);

		return $action;
	}

	/** Check if a user `$who` has admin level `$level` */
	public function checkExisting(string $who, int $level): bool {
		return !($this->getAdminLevel($who) !== $level);
	}
}
