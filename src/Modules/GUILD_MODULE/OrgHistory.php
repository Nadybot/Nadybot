<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\DBRow;

class OrgHistory extends DBRow {
	/**
	 * @param int     $id           Internal ID of this history entry
	 * @param ?string $actor        The person doing the action
	 * @param ?string $actee        Optional, the person the actor is acting on
	 * @param ?string $action       The action the actor is doing
	 * @param ?string $organization Name of the organization this action was done in
	 * @param ?int    $time         Timestamp when the action happened
	 */
	public function __construct(
		public int $id,
		public ?string $actor,
		public ?string $actee,
		public ?string $action,
		public ?string $organization,
		public ?int $time,
	) {
	}
}
