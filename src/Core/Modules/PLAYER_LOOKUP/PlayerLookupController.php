<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DB;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance,
	NCA\HasMigrations]
class PlayerLookupController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
	}
}
