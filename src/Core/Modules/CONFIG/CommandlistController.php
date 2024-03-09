<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DBSchema\CmdCfg,
	ModuleInstance,
	Text,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "cmdlist",
		accessLevel: "guild",
		description: "Shows a list of all commands on the bot",
		defaultStatus: 1
	)
]
class CommandlistController extends ModuleInstance {
	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private CommandManager $commandManager;

	/** Show a list of all commands, optionally only for the given access level */
	#[NCA\HandlesCommand("cmdlist")]
	public function cmdlistCommand(CmdContext $context, ?string $accessLevel): void {
		$cmds = $this->commandManager->getAll(true);
		if (isset($accessLevel)) {
			$cmds = $cmds->filter(function (CmdCfg $cmd) use ($accessLevel): bool {
				$cmd->permissions = (new Collection($cmd->permissions))->where("access_level", $accessLevel)
					->toArray();
				return count($cmd->permissions) > 0;
			});
		}
		$cmds = $cmds->sortBy("cmd");
		if ($cmds->isEmpty()) {
			$msg = "No commands were found.";
			$context->reply($msg);
			return;
		}
		$sets = $this->commandManager->getPermissionSets();
		$isMod = $this->accessManager->checkAccess($context->char->name, 'moderator');
		$lines = [];
		foreach ($cmds as $cmd) {
			$perms = new Collection($cmd->permissions);
			$numEnabled = $perms->where("enabled", true)->count();
			$numDisabled = $perms->where("enabled", false)->count();

			$links = "";
			if ($isMod) {
				$onLink = "<black>[on]<end>";
				if ($numDisabled > 0) {
					$onLink = "[" . $this->text->makeChatcmd('on', "/tell <myname> config cmd {$cmd->cmd} enable all") . "]";
				}
				$offLink = "<black>[off]<end>";
				if ($numEnabled > 0) {
					$offLink = "[" . $this->text->makeChatcmd('off', "/tell <myname> config cmd {$cmd->cmd} disable all") . "]";
				}
				$rightsLink = $this->text->makeChatcmd('rights', "/tell <myname> config cmd {$cmd->cmd}");
				$links = "[{$rightsLink}]  {$onLink}  {$offLink}";
			}
			$status = [];
			foreach ($sets as $set) {
				if (!isset($cmd->permissions[$set->name])) {
					$status []= "<black>{$set->letter}<end>";
				} elseif ($cmd->permissions[$set->name]->enabled) {
					$status []= "<on>{$set->letter}<end>";
				} else {
					$status []= "<off>{$set->letter}<end>";
				}
			}

			$lines []= "{$links}  [" . join("|", $status) . "] <highlight>{$cmd->cmd}<end>: {$cmd->description}";
		}

		$msg = $this->text->makeBlob(
			"Command List (" . $cmds->count() . ")",
			join("\n<pagebreak>", $lines)
		);
		$context->reply($msg);
	}
}
