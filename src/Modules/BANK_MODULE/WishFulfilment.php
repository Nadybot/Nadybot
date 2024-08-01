<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Nadybot\Core\{Attributes\DB, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable as SafeDateTimeImmutable;

#[DB\Table(name: 'wishlist_fulfilment', shared: DB\Shared::Yes)]
class WishFulfilment extends DBTable {
	#[DB\PK] public UuidInterface $id;
	public int $fulfilled_on;

	public function __construct(
		public UuidInterface $wish_id,
		public string $fulfilled_by,
		public int $amount=1,
		?int $fulfilled_on=null,
		?UuidInterface $id=null,
	) {
		$this->fulfilled_on = $fulfilled_on ?? time();
		$dt = null;
		if (isset($fulfilled_on) && !isset($id)) {
			$dt = (new SafeDateTimeImmutable())->setTimestamp($fulfilled_on);
		}
		$this->id = $id ?? Uuid::uuid7($dt);
	}
}
