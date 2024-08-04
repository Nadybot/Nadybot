<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'quote', shared: NCA\DB\Shared::Yes)]
class Quote extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public string $poster,
		public int $dt,
		public string $msg,
		?UuidInterface $id=null,
	) {
		$time = null;
		if (!isset($id)) {
			$time = (new DateTimeImmutable())->setTimestamp($dt);
		}
		$this->id = $id ?? Uuid::uuid7($time);
	}
}
