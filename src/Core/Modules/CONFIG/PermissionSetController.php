<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	ModuleInstance,
	ParamClass\PWord,
};
use Nadybot\Core\ParamClass\PRemove;

/**
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "permset",
		accessLevel: "superadmin",
		description: "Manages permission sets",
		help: "permset.txt",
		defaultStatus: 1
	)
]
class PermissinSetController extends ModuleInstance {
	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\HandlesCommand("permset")]
	public function permsetNewCommand(
		CmdContext $context,
		#[NCA\Str("new")] string $action,
		PWord $name,
		string $letter
	): void {
		try {
			$this->cmdManager->createPermissionSet($name(), $letter);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply("Permission set <highlight>{$name}<end> successfully created.");
	}

	#[NCA\HandlesCommand("permset")]
	public function permsetCloneCommand(
		CmdContext $context,
		#[NCA\Str("clone")] string $action,
		PWord $toClone,
		#[NCA\Str("into")] ?string $into,
		PWord $name,
		string $letter
	): void {
		try {
			$this->cmdManager->clonePermissionSet($toClone(), $name(), $letter);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply("Permission set <highlight>{$name}<end> successfully created.");
	}

	#[NCA\HandlesCommand("permset")]
	public function permsetRemoveCommand(
		CmdContext $context,
		PRemove $action,
		PWord $name
	): void {
		try {
			$this->cmdManager->deletePermissionSet($name());
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply("Permission set <highlight>{$name}<end> successfully deleted.");
	}
}
