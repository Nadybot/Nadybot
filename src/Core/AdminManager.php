<?php

namespace Budabot\Core;

/**
 * @Instance
 */
class AdminManager {

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @var \Budabot\Core\BuddylistManager $buddylistManager
	 * @Inject
	 */
	public $buddylistManager;

	/**
	 * Admin access levels of our admin users
	 * @var array $admins
	 */
	public $admins = array();

	/**
	 * Load the bot admins from database into $admins
	 *
	 * @return void
	 */
	public function uploadAdmins() {
		$this->db->exec("CREATE TABLE IF NOT EXISTS admin_<myname> (`name` VARCHAR(25) NOT NULL PRIMARY KEY, `adminlevel` INT)");

		$this->chatBot->vars["SuperAdmin"] = ucfirst(strtolower($this->chatBot->vars["SuperAdmin"]));

		$data = $this->db->query("SELECT * FROM admin_<myname> WHERE `name` = ?", $this->chatBot->vars["SuperAdmin"]);
		if (count($data) == 0) {
			$this->db->exec("INSERT INTO admin_<myname> (`adminlevel`, `name`) VALUES (?, ?)", '4', $this->chatBot->vars["SuperAdmin"]);
		} else {
			$this->db->exec("UPDATE admin_<myname> SET `adminlevel` = ? WHERE `name` = ?", '4', $this->chatBot->vars["SuperAdmin"]);
		}

		$data = $this->db->query("SELECT * FROM admin_<myname>");
		foreach ($data as $row) {
			$this->admins[$row->name]["level"] = $row->adminlevel;
		}
	}

	/**
	 * Demote someone from the admin position
	 *
	 * @param string $who Name of the person to demote
	 * @return void
	 */
	public function removeFromLists($who) {
		unset($this->admins[$who]);
		$this->db->exec("DELETE FROM admin_<myname> WHERE `name` = ?", $who);
		$this->buddylistManager->remove($who, 'admin');
	}

	/**
	 * Set the admin level of a user
	 *
	 * @param string $who The username to change
	 * @param int $intlevel The new accesslevel
	 * @return string Either "demoted" or "promoted"
	 */
	public function addToLists($who, $intlevel) {
		$action = '';
		if (isset($this->admins[$who])) {
			$this->db->exec("UPDATE admin_<myname> SET `adminlevel` = ? WHERE `name` = ?", $intlevel, $who);
			if ($this->admins[$who]["level"] > $intlevel) {
				$action = "demoted";
			} else {
				$action = "promoted";
			}
		} else {
			$this->db->exec("INSERT INTO admin_<myname> (`adminlevel`, `name`) VALUES (?, ?)", $intlevel, $who);
			$action = "promoted";
		}

		$this->admins[$who]["level"] = $intlevel;
		$this->buddylistManager->add($who, 'admin');

		return $action;
	}

	/**
	 * Check if a user $who has admin level $level
	 *
	 * @param string $who name of the user to check
	 * @param int $level Admin level to check
	 * @return bool
	 */
	public function checkExisting($who, $level) {
		if ($this->admins[$who]["level"] != $level) {
			return false;
		} else {
			return true;
		}
	}
}
