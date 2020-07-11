<?php

namespace Budabot\Core\Modules\PLAYER_LOOKUP;

use Budabot\Core\DB;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 */
class PlayerLookupController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		if ($this->db->getType() == DB::MYSQL) {
			$this->db->loadSQLFile($this->moduleName, 'players_mysql');
		} elseif ($this->db->getType() == DB::SQLITE) {
			$this->db->loadSQLFile($this->moduleName, 'players_sqlite');
		}
	}
}
