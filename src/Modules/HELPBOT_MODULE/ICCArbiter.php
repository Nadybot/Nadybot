<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use DateTime;
use Nadybot\Core\{Attributes as NCA, DBRow};

class ICCArbiter extends DBRow {
	public function __construct(
		public string $type,
		public DateTime $start,
		public DateTime $end,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
