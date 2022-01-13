<?php

namespace Nadybot\Core;

use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\Admin,
	DBSchema\Audit,
};

/**
 * Manage the bot admins
 */
#[NCA\Instance]
class AdminManager implements AccessLevelProvider {
	public const DB_TABLE = "admin_<myname>";

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public ConfigFile $config;

	/**
	 * Admin access levels of our admin users
	 * @var array<string,array<string,int>> $admins
	 */
	public array $admins = [];

	public function getSingleAccessLevel(string $sender): ?string {
		$level = $this->admins[$sender]["level"] ?? 0;
		if ($level >= 4) {
			return "admin";
		} elseif ($level >= 3) {
			return "mod";
		}
		return null;
	}

	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);
	}

	/**
	 * Load the bot admins from database into $admins
	 */
	public function uploadAdmins(): void {
		$this->db->table(self::DB_TABLE)->upsert(
			[
				"adminlevel" => 4,
				"name" => $this->config->superAdmin,
			],
			"name"
		);

		$this->db->table(self::DB_TABLE)
			->asObj(Admin::class)
			->each(function(Admin $row): void {
				if (isset($row->adminlevel)) {
					$this->admins[$row->name] = ["level" => $row->adminlevel];
				}
			});
	}

	/**
	 * Demote someone from the admin position
	 */
	public function removeFromLists(string $who, string $sender): void {
		$oldRank = $this->admins[$who]??[];
		unset($this->admins[$who]);
		$this->db->table(self::DB_TABLE)->where("name", $who)->delete();
		$this->buddylistManager->remove($who, 'admin');
		$audit = new Audit();
		$audit->actor = $sender;
		$audit->actee = $who;
		$audit->action = AccessManager::DEL_RANK;
		$alMod = $this->accessManager->getAccessLevels()["mod"];
		$audit->value = (string)($alMod - ($oldRank["level"] - $alMod));
		$this->accessManager->addAudit($audit);
	}

	/**
	 * Set the admin level of a user
	 *
	 * @return string Either "demoted" or "promoted"
	 */
	public function addToLists(string $who, int $intlevel, string $sender): string {
		$action = 'promoted';
		$alMod = $this->accessManager->getAccessLevels()["mod"];
		if (isset($this->admins[$who])) {
			$this->db->table(self::DB_TABLE)
				->where("name", $who)
				->update(["adminlevel" => $intlevel]);
			if ($this->admins[$who]["level"] > $intlevel) {
				$action = "demoted";
			}
			$audit = new Audit();
			$audit->actor = $sender;
			$audit->actee = $who;
			$audit->action = AccessManager::DEL_RANK;
			$audit->value = (string)($alMod - ($this->admins[$who]["level"] - $alMod));
			$this->accessManager->addAudit($audit);
		} else {
			$this->db->table(self::DB_TABLE)
				->insert(["adminlevel" => $intlevel, "name" => $who]);
		}

		$this->admins[$who]["level"] = $intlevel;
		$this->buddylistManager->add($who, 'admin');

		$audit = new Audit();
		$audit->actor = $sender;
		$audit->actee = $who;
		$audit->action = AccessManager::ADD_RANK;
		$audit->value = (string)($alMod - ($intlevel - $alMod));
		$this->accessManager->addAudit($audit);

		return $action;
	}

	/**
	 * Check if a user $who has admin level $level
	 */
	public function checkExisting(string $who, int $level): bool {
		if ($this->admins[$who]["level"] !== $level) {
			return false;
		}
		return true;
	}
}
