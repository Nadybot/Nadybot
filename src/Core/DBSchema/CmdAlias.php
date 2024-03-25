<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CmdAlias extends DBRow {
	public function __construct(
		public string $cmd,
		public string $alias,
		public ?string $module=null,
		public int $status=0,
	) {
	}
}
