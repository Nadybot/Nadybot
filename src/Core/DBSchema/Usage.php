<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes\DB, DBTable};

/** This table tracks whenever a command is being used to generate statistics */
#[DB\Table(name: 'usage')]
class Usage extends DBTable {
	/**
	 * @param string $type    Which permission set was used for this command
	 * @param string $command The actual name of the command, no parameters
	 * @param string $sender  The character name who executed the command
	 * @param int    $dt      Unix date and time when the command was executed
	 */
	public function __construct(
		public string $type,
		public string $command,
		public string $sender,
		public int $dt,
	) {
	}
}
