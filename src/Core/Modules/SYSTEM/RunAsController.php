<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandManager,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	Routing\Character,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'runas',
		accessLevel: 'superadmin',
		description: 'Execute a command as another character',
	)
]
class RunAsController extends ModuleInstance {
	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	/** Run a command as another character */
	#[NCA\HandlesCommand('runas')]
	public function runasCommand(CmdContext $context, PCharacter $character, string $command): void {
		$context->message = $command;
		$uid = $this->chatBot->getUid($character());
		if (!isset($uid)) {
			$context->reply("Character <highlight>{$character}<end> does not exist.");
			return;
		}
		if (!$this->accessManager->checkAccess($context->char->name, 'superadmin')
			&& $this->accessManager->compareCharacterAccessLevels($context->char->name, $character()) <= 0
		) {
			$context->reply("Error! Access level not sufficient to run commands as <highlight>{$character}<end>.");
			return;
		}
		$context->char = new Character($character(), $uid);
		$this->commandManager->processCmd($context);
	}
}
