<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};

#[NCA\DB\Table(name: 'formula', shared: NCA\DB\Shared::No)]
class Formula extends DBTable {
	public function __construct(
		#[NCA\DB\PK] public string $name,
		public string $formula,
	) {
	}
}
