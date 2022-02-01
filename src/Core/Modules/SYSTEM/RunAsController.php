<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	AccessManager,
	CmdContext,
	CommandManager,
	ModuleInstance,
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
	)
]
class RunAsController extends ModuleInstance {

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	/** Run a command as another character */
	#[NCA\HandlesCommand("runas")]
	public function runasCommand(CmdContext $context, PCharacter $character, string $command): void {
		$context->message = $command;
		$this->chatBot->getUid(
			$character(),
			function (?int $uid, CmdContext $context, string $character): void {
				if (!isset($uid)) {
					$context->reply("Character <highlight>{$character}<end> does not exist.");
					return;
				}
				if (!$this->accessManager->checkAccess($context->char->name, "superadmin")
					&& $this->accessManager->compareCharacterAccessLevels($context->char->name, $character) <= 0
				) {
					$context->reply("Error! Access level not sufficient to run commands as <highlight>$character<end>.");
					return;
				}
				$context->char = new Character($character, $uid);
				$this->commandManager->processCmd($context);
			},
			$context,
			$character()
		);
	}
}
