<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\DB;

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
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
	}
}
