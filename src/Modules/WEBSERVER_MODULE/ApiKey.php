<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use DateTimeInterface;
use Nadybot\Core\Attributes\DB\{PK, Table};
use Nadybot\Core\DBTable;
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[Table(name: 'api_key')]
class ApiKey extends DBTable {
	public DateTimeInterface $created;
	#[PK] public UuidInterface $id;

	public function __construct(
		public string $character,
		public string $token,
		public string $pubkey,
		?UuidInterface $id=null,
		public int $last_sequence_nr=0,
		?DateTimeInterface $created=null,
	) {
		$this->created = $created ?? new DateTimeImmutable();
		$this->id = $id ?? Uuid::uuid7($this->created);
	}
}
