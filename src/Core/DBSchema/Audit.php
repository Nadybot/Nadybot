<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use DateTime;
use Nadybot\Core\DBRow;

class Audit extends DBRow {
	/** ID of this audit entry */
	public int $id;

	/** The person doing something */
	public string $actor;

	/** The person the actor is interacting with. Not set if not applicable */
	public ?string $actee = null;

	/** What did the actor do */
	public string $action;

	/** Optional value for the action */
	public ?string $value = null;

	/** time when it happened */
	public DateTime $time;

	public function __construct() {
		$this->time = new DateTime();
	}
}
