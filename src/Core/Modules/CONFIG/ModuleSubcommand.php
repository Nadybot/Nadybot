<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\CmdCfg;

class ModuleSubcommand extends CmdCfg {
	public const TYPE_COMMAND = 'cmd';
	public const TYPE_SUBCOMMAND = 'subcmd';

	public function __construct(CmdCfg $src) {
		$this->module = $src->module;
		$this->cmdevent = $src->cmdevent;
		$this->file = $src->file;
		$this->cmd = $src->cmd;
		$this->description = $src->description;
		$this->verify = $src->verify;
		$this->dependson = $src->dependson;
	}
}
