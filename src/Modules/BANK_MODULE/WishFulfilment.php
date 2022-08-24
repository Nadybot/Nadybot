<?php declare(strict_types=1);

namespace Nadybot\Modules\BANK_MODULE;

use Nadybot\Core\DBRow;

class WishFulfilment extends DBRow {
	public int $id;
	public int $wish_id;
	public int $amount = 1;
	public int $fulfilled_on;
	public string $fulfilled_by;

	public function __construct() {
		$this->fulfilled_on = time();
	}
}
