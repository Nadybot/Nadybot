<?php

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Admin;
use Nadybot\Core\DBSchema\Audit;

/**
 * @Instance
 * Manage the bot admins
 */
class AdminManager {
	public const DB_TABLE = "admin_<myname>";

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public AccessManager $accessManager;

	/**
	 * Admin access levels of our admin users
	 * @var array<string,array<string,int>> $admins
	 */
	public array $admins = [];

	/**
	 * Load the bot admins from database into $admins
	 */
	public function uploadAdmins(): void {
		$this->chatBot->vars["SuperAdmin"] = ucfirst(strtolower($this->chatBot->vars["SuperAdmin"]));

		$this->db->table(self::DB_TABLE)->upsert(
			[
				"adminlevel" => 4,
				"name" => $this->chatBot->vars["SuperAdmin"],
			],
			"name"
		);

		$this->db->table(self::DB_TABLE)
			->asObj(Admin::class)
			->each(function(Admin $row) {
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
