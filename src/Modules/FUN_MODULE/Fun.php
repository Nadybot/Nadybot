<?php declare(strict_types=1);

namespace Nadybot\Modules\FUN_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Fun extends DBRow {
	public function __construct(
		public string $type,
		public string $content,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
