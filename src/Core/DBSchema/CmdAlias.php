<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{
	Attributes\DB\Table,
	DBTable,
	Types\Status,
};

/** This represents an alias for a command */
#[Table(name: 'cmd_alias')]
class CmdAlias extends DBTable {
	/**
	 * @param string      $cmd    The command to execute when $alias is typed
	 * @param string      $alias  The alias for $cmd
	 * @param string|null $module The name of the module that set up the alias,
	 *                            or null if it was a player
	 * @param Status      $status The state of this alias (enabled or disabled)
	 */
	public function __construct(
		public string $cmd,
		public string $alias,
		public ?string $module=null,
		public Status $status=Status::Disabled,
	) {
	}

	/** Check if $that is identical to $this */
	public function sameAS(self $that): bool {
		return $this->cmd === $that->cmd
			&& $this->alias === $that->alias
			&& $this->module === $that->module
			&& $this->status === $that->status;
	}
}
