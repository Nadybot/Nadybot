<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;
use Nadybot\Core\Attributes as NCA;

class CmdPermissionSet extends DBRow {
	public string $name;
	public string $letter;

	/**
	 * @var CmdPermSetMapping[]
	 */
	#[NCA\DB\Ignore]
	public array $mappings = [];
}
