<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\{Shared, Table};
use Nadybot\Core\{DBTable};

#[Table(name: 'profession', shared: Shared::Yes)]
class Profession extends DBTable {
	public function __construct(
		public int $id,
		public string $name,
	) {
	}
}
