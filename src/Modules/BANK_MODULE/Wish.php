<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{Attributes as NCA, DBRow};

class Wish extends DBRow {
	public int $created_on;

	/** @var Collection<WishFulfilment> */
	#[NCA\DB\Ignore]
	public Collection $fulfilments;

	public function __construct(
		public string $created_by,
		public string $item,
		?int $created_on=null,
		public ?int $expires_on=null,
		public int $amount=1,
		public ?string $from=null,
		public bool $fulfilled=false,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
		$this->created_on = $created_on ?? time();
		$this->fulfilments = new Collection();
	}

	/** Get how many items are still needed */
	public function getRemaining(): int {
		$numFulfilled = $this->fulfilments->sum(static fn (WishFulfilment $f) => $f->amount);
		return (int)max(0, $this->amount - $numFulfilled);
	}

	public function isExpired(): bool {
		return isset($this->expires_on) && $this->expires_on < time();
	}
}
