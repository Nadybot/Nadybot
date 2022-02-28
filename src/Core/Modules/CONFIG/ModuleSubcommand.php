<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\CmdCfg;

class ModuleSubcommand extends CmdCfg {
	public const TYPE_COMMAND = "cmd";
	public const TYPE_SUBCOMMAND = "subcmd";

	public function __construct(CmdCfg $src) {
		foreach (get_object_vars($src) as $key => $value) {
			$this->{$key} = $value;
		}
	}
}
