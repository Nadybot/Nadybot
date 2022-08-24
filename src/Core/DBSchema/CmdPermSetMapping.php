<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\JSON;
use Nadybot\Core\DBRow;

class CmdPermSetMapping extends DBRow {
	#[JSON\Ignore]
	public ?int $id = null;

	/** The permission set to map $source to */
	public string $permission_set;

	/** The command source to map */
	public string $source;

	/** The symbol that triggers a command if it's the first letter */
	public string $symbol;

	/** Is the symbol required to interpret the msg as command or optional */
	public bool $symbol_optional = false;

	/** Shall we report an error if the command doesn't exist */
	public bool $feedback = true;
}
