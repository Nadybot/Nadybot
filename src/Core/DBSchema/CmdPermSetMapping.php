<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'cmd_permission_set_mapping')]
class CmdPermSetMapping extends DBTable {
	#[NCA\JSON\Ignore] #[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string $permission_set  The permission set to map $source to
	 * @param string $source          The command source to map
	 * @param string $symbol          The symbol that triggers a command if it's the first letter
	 * @param bool   $symbol_optional Is the symbol required to interpret the msg as command or optional
	 * @param bool   $feedback        Shall we report an error if the command doesn't exist
	 */
	public function __construct(
		public string $permission_set,
		public string $source,
		public string $symbol,
		public bool $symbol_optional=false,
		public bool $feedback=true,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
