<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CmdPermission extends DBRow {
	/** @json:ignore */
	public ?int $id=null;

	/** The name of the permission-set */
	public string $permission_set;

	/** @json:ignore */
	public string $cmd;

	/** The access-level (member,admin,guest,all,etc) */
	public string $access_level;

	/** Is the (sub-)command enabled on this permission-set */
	public bool $enabled;
}
