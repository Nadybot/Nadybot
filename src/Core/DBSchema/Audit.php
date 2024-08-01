<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\Table;
use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[Table(name: 'audit')]
class Audit extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string            $actor  The person doing something
	 * @param string            $action What did the actor do
	 * @param ?string           $actee  The person the actor is interacting with. Not set if not applicable
	 * @param ?string           $value  Optional value for the action
	 * @param DateTimeImmutable $time   time when it happened
	 * @param ?UuidInterface    $id     ID of this audit entry (if known)
	 */
	public function __construct(
		public string $actor,
		public string $action,
		public ?string $actee=null,
		public ?string $value=null,
		public DateTimeImmutable $time=new DateTimeImmutable(),
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
