<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Roll extends DBRow {
	public function __construct(
		public ?int $time,
		public ?string $name,
		public ?string $options,
		public ?string $result,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
