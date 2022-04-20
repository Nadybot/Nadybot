<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes as NCA;

class ExtCmdPermissionSet extends CmdPermissionSet {
	/**
	 * @var CmdPermSetMapping[]
	 */
	#[NCA\DB\Ignore]
	public array $mappings = [];
}
