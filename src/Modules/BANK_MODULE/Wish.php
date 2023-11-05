<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{Attributes as NCA, DBRow};

class Wish extends DBRow {
	public int $id;
	public int $created_on;
	public ?int $expires_on = null;
	public string $created_by;
	public string $item;
	public int $amount = 1;
	public ?string $from = null;
	public bool $fulfilled = false;

	/** @var Collection<WishFulfilment> */
	#[NCA\DB\Ignore]
	public Collection $fulfilments;

	public function __construct() {
		$this->created_on = time();
		$this->fulfilments = new Collection();
	}

	/** Get how many items are still needed */
	public function getRemaining(): int {
		$numFulfilled = $this->fulfilments->sum(fn (WishFulfilment $f) => $f->amount);
		return (int)max(0, $this->amount - $numFulfilled);
	}

	public function isExpired(): bool {
		return isset($this->expires_on) && $this->expires_on < time();
	}
}
