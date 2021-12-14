<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandManager,
	Nadybot,
};
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\Routing\Character;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "runas",
		accessLevel: "superadmin",
		description: "Execute a command as another character",
		help: "runas.txt"
	)
]
class RunAsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\HandlesCommand("runas")]
	public function runasCommand(CmdContext $context, PCharacter $name, string $command): void {
		$context->message = $command;
		$this->chatBot->getUid(
			$name(),
			function (?int $uid, CmdContext $context, string $name): void {
				if (!isset($uid)) {
					$context->reply("Player <highlight>{$name}<end> does not exist.");
					return;
				}
				if (!$this->accessManager->checkAccess($context->char->name, "superadmin")
					&& $this->accessManager->compareCharacterAccessLevels($context->char->name, $name) <= 0
				) {
					$context->reply("Error! Access level not sufficient to run commands as <highlight>$name<end>.");
					return;
				}
				$context->char = new Character($name, $uid);
				$this->commandManager->processCmd($context);
			},
			$context,
			$name()
		);
	}
}
