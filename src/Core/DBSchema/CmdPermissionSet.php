<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CmdPermissionSet extends DBRow {
	public function __construct(
		public string $name,
		public string $letter,
	) {
	}
}
