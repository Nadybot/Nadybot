<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Nadybot\Core\Attributes\DB\Shared;
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

/** A migration that the bot has already successfully applied */
#[NCA\DB\Table(name: 'migrations', shared: Shared::Both)]
class Migration extends DBTable {
	/** Unique identifier of this migration */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string             $module     Name of the module providing the migration
	 * @param string             $migration  Name of the migration itself
	 * @param DateTimeImmutable  $applied_at When was the migration applied?
	 * @param null|UuidInterface $id         Unique identifier or `null` for automatic
	 *                                       creation of a UUID
	 */
	public function __construct(
		public string $module,
		public string $migration,
		public DateTimeImmutable $applied_at,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7($applied_at);
	}
}
