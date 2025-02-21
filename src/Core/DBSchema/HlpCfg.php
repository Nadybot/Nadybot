<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{Attributes as NCA, DBTable};

#[NCA\DB\Table(name: 'hlpcfg')]
class HlpCfg extends DBTable {
	public function __construct(
		public string $name,
		public string $module,
		public string $file,
		public string $description,
		public AccessLevel $admin,
		public int $verify=0,
	) {
	}
}
