<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class WishFulfilment extends DBRow {
	public int $fulfilled_on;

	public function __construct(
		public int $wish_id,
		public string $fulfilled_by,
		public int $amount=1,
		?int $fulfilled_on=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
		$this->fulfilled_on = $fulfilled_on ?? time();
	}
}
