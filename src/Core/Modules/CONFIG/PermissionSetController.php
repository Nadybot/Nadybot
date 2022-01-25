<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Closure;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DB,
	DBSchema\CmdPermissionSet,
	ModuleInstance,
	ParamClass\PRemove,
	ParamClass\PWord,
	SettingManager,
	Text,
};

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
	),
]
class PermissionSetController extends ModuleInstance {
	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\Inject]
	public Text $text;

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

	#[NCA\HandlesCommand("permset")]
	public function permsetListCommand(CmdContext $context): void {
		$sets = $this->cmdManager->getPermissionSets(true);
		$blocks = $sets->map(Closure::fromCallable([$this, "renderPermissionSet"]));
		$blob = $blocks->join("\n\n<pagebreak>");
		$context->reply(
			$this->text->makeBlob("Permission sets (" . $blocks->count() . ")", $blob)
		);
	}

	protected function renderPermissionSet(CmdPermissionSet $set): string {
		$block = "<header2>{$set->name}<end>\n".
			"<tab>Letter: <highlight>{$set->letter}<end>\n".
			"<tab>Channels: <highlight>".
			(new Collection($set->mappings))->pluck("source")
				->join("<end>, <highlight>", "<end> and <highlight>") . "<end>";
		if (empty($set->mappings)) {
			$block .= "\n<tab>Actions: [".
				$this->text->makeChatcmd("delete", "/tell <myname> permset rem {$set->name}").
				"]";
		}
		return $block;
	}
}
