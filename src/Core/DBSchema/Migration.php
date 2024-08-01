<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\DB\Shared;
use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'migrations', shared: Shared::Both)]
class Migration extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public string $module,
		public string $migration,
		public DateTimeImmutable $applied_at,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7($applied_at);
	}
}
