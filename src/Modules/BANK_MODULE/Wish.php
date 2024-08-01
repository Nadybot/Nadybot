<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{Attributes\DB, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[DB\Table(name: 'wishlist', shared: DB\Shared::Yes)]
class Wish extends DBTable {
	public int $created_on;
	#[DB\PK] public UuidInterface $id;

	/** @var Collection<int,WishFulfilment> */
	#[DB\Ignore]
	public Collection $fulfilments;

	public function __construct(
		public string $created_by,
		public string $item,
		?int $created_on=null,
		public ?int $expires_on=null,
		public int $amount=1,
		public ?string $from=null,
		public bool $fulfilled=false,
		?UuidInterface $id=null,
	) {
		$this->created_on = $created_on ?? time();
		$dt = null;
		if (isset($created_on) && !isset($id)) {
			$dt = (new DateTimeImmutable())->setTimestamp($created_on);
		}
		$this->fulfilments = new Collection();
		$this->id = $id ?? Uuid::uuid7($dt);
	}

	/** Get how many items are still needed */
	public function getRemaining(): int {
		/** @var int */
		$numFulfilled = $this->fulfilments->sum(static fn (WishFulfilment $f): int => $f->amount);
		return max(0, $this->amount - $numFulfilled);
	}

	public function isExpired(): bool {
		return isset($this->expires_on) && $this->expires_on < time();
	}
}
