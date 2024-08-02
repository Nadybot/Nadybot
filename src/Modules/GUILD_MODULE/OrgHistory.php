<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Shared, Table};
use Nadybot\Core\DBTable;
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[Table(name: 'org_history', shared: Shared::Yes)]
class OrgHistory extends DBTable {
	/** Internal ID of this history entry */
	#[PK] public UuidInterface $id;

	/**
	 * @param ?string        $actor        The person doing the action
	 * @param ?string        $actee        Optional, the person the actor is acting on
	 * @param ?string        $action       The action the actor is doing
	 * @param ?string        $organization Name of the organization this action was done in
	 * @param ?int           $time         Timestamp when the action happened
	 * @param ?UuidInterface $id           Internal ID of this history entry
	 */
	public function __construct(
		public ?string $actor,
		public ?string $actee,
		public ?string $action,
		public ?string $organization,
		public ?int $time,
		?UuidInterface $id=null,
	) {
		$dt = null;
		if (isset($time) && !isset($id)) {
			$dt = (new DateTimeImmutable())->setTimestamp($time);
		}
		$this->id = $id ?? Uuid::uuid7($dt);
	}
}
