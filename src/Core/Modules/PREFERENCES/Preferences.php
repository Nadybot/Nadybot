<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES;

use Nadybot\Core\DB;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class Preferences {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;
	
	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'preferences');
	}
	
	public function save(string $sender, string $name, string $value): void {
		$sender = ucfirst(strtolower($sender));
		$name = strtolower($name);

		if ($this->get($sender, $name) === null) {
			$this->db->exec("INSERT INTO preferences_<myname> (sender, name, value) VALUES (?, ?, ?)", $sender, $name, $value);
		} else {
			$this->db->exec("UPDATE preferences_<myname> SET value = ? WHERE sender = ? AND name = ?", $value, $sender, $name);
		}
	}

	public function get(string $sender, string $name): ?string {
		$sender = ucfirst(strtolower($sender));
		$name = strtolower($name);

		$row = $this->db->queryRow("SELECT * FROM preferences_<myname> WHERE sender = ? AND name = ?", $sender, $name);
		if ($row === null) {
			return null;
		}
		return $row->value;
	}
}
