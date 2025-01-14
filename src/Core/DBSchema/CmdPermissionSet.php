<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};

#[NCA\DB\Table(name: 'cmd_permission_set')]
/** A set of permissions for commands that can be mapped to a command-source */
class CmdPermissionSet extends DBTable {
	public function __construct(
		#[NCA\DB\PK] public string $name,
		public string $letter,
	) {
	}
}
