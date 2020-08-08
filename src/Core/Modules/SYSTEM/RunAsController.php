<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	AccessManager,
	CommandManager,
	CommandReply,
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
	public function runasCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$command = $args[2];
		if ($this->accessManager->checkAccess($sender, "superadmin") || $this->accessManager->compareCharacterAccessLevels($sender, $name) > 0) {
			$this->commandManager->process($channel, $command, $name, $sendto);
		} else {
			$sendto->reply("Error! Access level not sufficient to run commands as <highlight>$name<end>.");
		}
	}
}
