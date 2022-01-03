<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DB;
use Nadybot\Core\Instance;

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance,
	NCA\HasMigrations]
class PlayerLookupController extends Instance {

		#[NCA\Inject]
	public DB $db;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
	}
}
