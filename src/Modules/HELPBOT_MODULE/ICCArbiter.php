<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use DateTimeInterface;
use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'icc_arbiter', shared: NCA\DB\Shared::Yes)]
class ICCArbiter extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public string $type,
		public DateTimeInterface $start,
		public DateTimeInterface $end,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
