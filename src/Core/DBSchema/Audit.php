<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Safe\DateTime;

class Audit extends DBRow {
	/**
	 * @param string   $actor  The person doing something
	 * @param string   $action What did the actor do
	 * @param ?string  $actee  The person the actor is interacting with. Not set if not applicable
	 * @param ?string  $value  Optional value for the action
	 * @param DateTime $time   time when it happened
	 * @param ?int     $id     ID of this audit entry, or null if not determined yet
	 */
	public function __construct(
		public string $actor,
		public string $action,
		public ?string $actee=null,
		public ?string $value=null,
		public DateTime $time=new DateTime(),
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
