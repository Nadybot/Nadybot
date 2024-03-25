<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use DateTime;
use Nadybot\Core\{Attributes as NCA, DBRow};

class Migration extends DBRow {
	public function __construct(
		public string $module,
		public string $migration,
		public DateTime $applied_at,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
