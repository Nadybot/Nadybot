<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class CmdPermission extends DBRow {
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
		#[NCA\JSON\Ignore] #[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
