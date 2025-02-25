<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};

/** A set of permissions for commands that can be mapped to a command-source */
#[NCA\DB\Table(name: 'cmd_permission_set')]
class CmdPermissionSet extends DBTable {
	/**
	 * @param string $name   Name of the permission set
	 * @param string $letter A single letter to represent it in the !config overview
	 */
	public function __construct(
		#[NCA\DB\PK] public string $name,
		public string $letter,
	) {
	}
}
