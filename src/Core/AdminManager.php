<?php

namespace Nadybot\Core;

use Nadybot\Core\DBSchema\Admin;

/**
 * @Instance
 * Manage the bot admins
 */
class AdminManager {

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
		$this->db->exec("CREATE TABLE IF NOT EXISTS `admin_<myname>` (`name` VARCHAR(25) NOT NULL PRIMARY KEY, `adminlevel` INT)");

		$this->chatBot->vars["SuperAdmin"] = ucfirst(strtolower($this->chatBot->vars["SuperAdmin"]));

		/** @var Admin[] $data */
		$data = $this->db->fetchAll(Admin::class, "SELECT * FROM `admin_<myname>` WHERE `name` = ?", $this->chatBot->vars["SuperAdmin"]);
		if (count($data) === 0) {
			$this->db->exec("INSERT INTO `admin_<myname>` (`adminlevel`, `name`) VALUES (?, ?)", '4', $this->chatBot->vars["SuperAdmin"]);
		} else {
			$this->db->exec("UPDATE `admin_<myname>` SET `adminlevel` = ? WHERE `name` = ?", '4', $this->chatBot->vars["SuperAdmin"]);
		}

		/** @var Admin[] $data */
		$data = $this->db->fetchAll(Admin::class, "SELECT * FROM `admin_<myname>`");
		foreach ($data as $row) {
			$this->admins[$row->name]["level"] = $row->adminlevel;
		}
	}

	/**
	 * Demote someone from the admin position
	 */
	public function removeFromLists(string $who): void {
		unset($this->admins[$who]);
		$this->db->exec("DELETE FROM `admin_<myname>` WHERE `name` = ?", $who);
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
			$this->db->exec("UPDATE `admin_<myname>` SET `adminlevel` = ? WHERE `name` = ?", $intlevel, $who);
			if ($this->admins[$who]["level"] > $intlevel) {
				$action = "demoted";
			}
		} else {
			$this->db->exec("INSERT INTO `admin_<myname>` (`adminlevel`, `name`) VALUES (?, ?)", $intlevel, $who);
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
