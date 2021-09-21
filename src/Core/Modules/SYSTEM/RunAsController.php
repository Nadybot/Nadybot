<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandManager,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'runas',
 *		accessLevel = 'superadmin',
 *		description = 'Execute a command as another character',
 *		help        = 'runas.txt'
 *	)
 */
class RunAsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public CommandManager $commandManager;

	/**
	 * @HandlesCommand("runas")
	 * @Matches("/^runas ([a-z0-9-]+) (.+)$/i")
	 */
	public function runasCommand(CmdContext $context, string $name, string $command): void {
		$name = ucfirst(strtolower($name));
		if (!$this->accessManager->checkAccess($context->sender, "superadmin") && $this->accessManager->compareCharacterAccessLevels($context->sender, $name) <= 0) {
			$context->reply("Error! Access level not sufficient to run commands as <highlight>$name<end>.");
			return;
		}
		$context->message = $command;
		$context->sender = $name;
		$this->commandManager->processCmd($context);
	}
}
