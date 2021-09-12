<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\DBRow;

class OrgHistory extends DBRow {
	/** Internal ID of this history entry */
	public int $id;

	/** The person doing the action */
	public ?string $actor;

	/** Optional, the person the actor is acting on */
	public ?string $actee;

	/** The action the actor is doing */
	public ?string $action;

	/** Name of the organization this action was done in */
	public ?string $organization;

	/** Timestamp when the action happened */
	public ?int $time;
}
