<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'cmd_permission')]
/** The permission required to execute a command via a specific command-channel */
class CmdPermission extends DBTable {
	#[NCA\JSON\Ignore] #[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string $permission_set The name of the permission-set
	 * @param string $access_level   The access-level (member,admin,guest,all,etc)
	 * @param bool   $enabled        Is the (sub-)command enabled on this permission-set
	 */
	public function __construct(
		public string $permission_set,
		#[NCA\JSON\Ignore] public string $cmd,
		public string $access_level,
		public bool $enabled,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
