<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Quote extends DBRow {
	public function __construct(
		public string $poster,
		public int $dt,
		public string $msg,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
