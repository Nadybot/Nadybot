<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\CmdCfg;

/** A (sub-)command entry of the bot */
class ModuleSubcommand extends CmdCfg {
	public const TYPE_COMMAND = 'cmd';
	public const TYPE_SUBCOMMAND = 'subcmd';

	public static function fromCmdCfg(CmdCfg $src): static {
		return new static(
			module: $src->module,
			cmdevent: $src->cmdevent,
			file: $src->file,
			cmd: $src->cmd,
			description: $src->description,
			verify: $src->verify,
			dependson: $src->dependson,
		);
	}
}
