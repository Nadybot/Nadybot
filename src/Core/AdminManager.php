<?php

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Admin;

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
				$this->admins[$row->name] = ["level" => $row->adminlevel];
			});
	}

	/**
	 * Demote someone from the admin position
	 */
	public function removeFromLists(string $who): void {
		unset($this->admins[$who]);
		$this->db->table(self::DB_TABLE)->where("name", $who)->delete();
		$this->buddylistManager->remove($who, 'admin');
	}

	/**
	 * Set the admin level of a user
	 *
	 * @return string Either "demoted" or "promoted"
	 */
	public function addToLists(string $who, int $intlevel): string {
		$action = 'promoted';
		if (isset($this->admins[$who])) {
			$this->db->table(self::DB_TABLE)
				->where("name", $who)
				->update(["adminlevel" => $intlevel]);
			if ($this->admins[$who]["level"] > $intlevel) {
				$action = "demoted";
			}
		} else {
			$this->db->table(self::DB_TABLE)
				->insert(["adminlevel" => $intlevel, "name" => $who]);
		}

		$this->admins[$who]["level"] = $intlevel;
		$this->buddylistManager->add($who, 'admin');

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
