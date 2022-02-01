<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Closure;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DBSchema\ExtCmdPermissionSet,
	ModuleInstance,
	ParamClass\PRemove,
	ParamClass\PWord,
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
		defaultStatus: 1
	),
]
class PermissionSetController extends ModuleInstance {
	#[NCA\Inject]
	public CommandManager $cmdManager;

	#[NCA\Inject]
	public Text $text;

	/** Create a new permission &lt;name&gt; set with default permissions */
	#[NCA\HandlesCommand("permset")]
	#[NCA\Help\Prologue(
		"<header2>Permission sets<end>\n\n".
		"Permission sets describe which commands should be enabled or disabled,\n".
		"but also which access level is required to execute each command.\n".
		"Since you might want to have different permissions for commands via tells\n".
		"and the guild channel, or you want to disable certain commands in the\n".
		"private channel, you might want to use more than just one permission set.\n\n".
		"By default there are 3 pre-defined permission sets: msg, priv and guild.\n".
		"Each permission set has a name and a letter. The letter is used to show\n".
		"if a command is enabled/disable for a permission set in the <symbol>config\n".
		"command.\n"
	)]
	public function permsetNewCommand(
		CmdContext $context,
		#[NCA\Str("new", "create")] string $action,
		PWord $name,
		?string $letter
	): void {
		try {
			$this->cmdManager->createPermissionSet($name(), $letter ?? substr($name(), 0, 1));
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply("Permission set <highlight>{$name}<end> successfully created.");
	}

	/** Create a new permission set based on another permission set */
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

	/** Delete a permission set that is not used */
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

	/** Change the name of a permission set */
	#[NCA\HandlesCommand("permset")]
	public function permsetRenameCommand(
		CmdContext $context,
		#[NCA\Str("rename")] string $action,
		PWord $oldName,
		#[NCA\Str("to")] ?string $to,
		PWord $newName
	): void {
		$old = $this->cmdManager->getPermissionSet($oldName());
		if (!isset($old)) {
			$context->reply("The permission set <highlight>{$oldName}<end> doesn't exist.");
			return;
		}
		$old->name = $newName();
		try {
			$this->cmdManager->changePermissionSet($oldName(), $old);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply(
			"Permission set <highlight>{$oldName}<end> successfully renamed to <highlight>{$newName}<end>."
		);
	}

	/** Change the letter of a permission set */
	#[NCA\HandlesCommand("permset")]
	public function permsetChangeLetterCommand(
		CmdContext $context,
		#[NCA\Str("letter")] string $action,
		PWord $name,
		PWord $newLetter
	): void {
		$old = $this->cmdManager->getPermissionSet($name());
		if (!isset($old)) {
			$context->reply("The permission set <highlight>{$name}<end> doesn't exist.");
			return;
		}
		$oldLetter = $old->letter;
		$old->letter = strtoupper($newLetter());
		try {
			$this->cmdManager->changePermissionSet($name(), $old);
		} catch (Exception $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply(
			"Changed the letter of Permission set <highlight>{$name}<end> ".
			"from <highlight>{$oldLetter}<end> to <highlight>{$old->letter}<end>."
		);
	}

	/** Show a list of all permission sets */
	#[NCA\HandlesCommand("permset")]
	public function permsetListCommand(CmdContext $context): void {
		$sets = $this->cmdManager->getExtPermissionSets();
		$blocks = $sets->map(Closure::fromCallable([$this, "renderPermissionSet"]));
		$blob = $blocks->join("\n\n<pagebreak>");
		$context->reply(
			$this->text->makeBlob("Permission sets (" . $blocks->count() . ")", $blob)
		);
	}

	protected function renderPermissionSet(ExtCmdPermissionSet $set): string {
		$channelNames = "&lt;none&gt;";
		if (!empty($set->mappings)) {
			$channelNames = (new Collection($set->mappings))->pluck("source")
				->join("<end>, <highlight>", "<end> and <highlight>");
		}
		$block = "<header2>{$set->name}<end>\n".
			"<tab>Letter: <highlight>{$set->letter}<end>\n".
			"<tab>Channels: <highlight>{$channelNames}<end>";
		if (empty($set->mappings)) {
			$block .= "\n<tab>Actions: [".
				$this->text->makeChatcmd("delete", "/tell <myname> permset rem {$set->name}").
				"]";
		}
		return $block;
	}
}
