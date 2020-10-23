<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

class ModuleCommand extends ModuleSubommand {
	/**
	 * A list of subcommands for this command.
	 * Subcommands can have different rights, but
	 * cannot be enabled without the command itself
	 * being enabled.
	 *
	 * @var ModuleSubommand[]
	 */
	public array $subcommands = [];
}
