<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes as NCA;

/** A set of permissions for commands that can be mapped to a command-source */
class ExtCmdPermissionSet extends CmdPermissionSet {
	/** @var list<CmdPermSetMapping> */
	#[NCA\DB\Ignore]
	public array $mappings = [];
}
