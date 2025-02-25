<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBTable};

/** A single bot-command */
#[NCA\DB\Table(name: 'cmdcfg')]
class CmdCfg extends DBTable {
	/**
	 * @var array<string,CmdPermission>
	 *
	 * @json-var CmdPermission[]
	 */
	#[NCA\DB\Ignore]
	#[NCA\JSON\Map('array_values')]
	public array $permissions = [];

	/**
	 * @param string $module      Name of the module that defines the command
	 * @param string $cmdevent    Type of the command:
	 *                            * 'cmd': A command
	 *                            * 'subcmd': A sub-command
	 * @param string $file        The file in which the command is defined
	 * @param string $cmd         The actual command name
	 * @param string $description A description what the command does
	 * @param int    $verify      Internally used to track if a command is still defined by the bot
	 * @param string $dependson   For sub-commands, this is the name of the parent command,
	 *                            for commands, this is always `'none'`
	 */
	final public function __construct(
		#[NCA\JSON\Ignore] public string $module,
		#[NCA\JSON\Ignore] public string $cmdevent,
		#[NCA\JSON\Ignore] public string $file,
		#[NCA\DB\PK] public string $cmd,
		public string $description='none',
		#[NCA\JSON\Ignore] public int $verify=0,
		#[NCA\JSON\Ignore] public string $dependson='none',
	) {
	}
}
