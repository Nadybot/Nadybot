<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	CmdContext,
	CommandAlias,
	CommandManager,
	Text,
};
use Nadybot\Core\DBSchema\CmdAlias;
use Nadybot\Core\ParamClass\PRemove;
use Nadybot\Core\ParamClass\PWord;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'alias',
 *		accessLevel   = 'mod',
 *		description   = 'Manage command aliases',
 *		help          = 'alias.txt',
 *		defaultStatus = '1'
 *	)
 */
class AliasController {

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public Text $text;

	/**
	 * @HandlesCommand("alias")
	 * @Mask $action add
	 * @Mask $alias ("[a-z 0-9]+")
	 */
	public function aliasAddCommand1(CmdContext $context, string $action, string $alias, string $command): void {
		$this->aliasAddCommand($context, substr($alias, 1, -1), $command);
	}

	/**
	 * @HandlesCommand("alias")
	 * @Mask $action add
	 * @Mask $alias ('[a-z 0-9]+')
	 */
	public function aliasAddCommand2(CmdContext $context, string $action, string $alias, string $command): void {
		$this->aliasAddCommand($context, substr($alias, 1, -1), $command);
	}

	/**
	 * @HandlesCommand("alias")
	 * @Mask $action add
	 */
	public function aliasAddCommand3(CmdContext $context, string $action, PWord $alias, string $command): void {
		$this->aliasAddCommand($context, $alias(), $command);
	}

	public function aliasAddCommand(CmdContext $context, string $alias, string $cmd): void {
		$alias = strtolower($alias);

		$aliasObj = new CmdAlias();
		$aliasObj->module = '';
		$aliasObj->cmd = $cmd;
		$aliasObj->alias = $alias;
		$aliasObj->status = 1;

		$commands = $this->commandManager->get($alias);
		$enabled = false;
		foreach ($commands as $command) {
			if ($command->status == 1) {
				$enabled = true;
				break;
			}
		}
		$row = $this->commandAlias->get($alias);
		if ($enabled) {
			$msg = "Cannot add alias <highlight>{$alias}<end> since there is already an active command with that name.";
		} elseif ($row === null) {
			$this->commandAlias->add($aliasObj);
			$this->commandAlias->activate($cmd, $alias);
			$msg = "Alias <highlight>{$alias}<end> for command <highlight>{$cmd}<end> added successfully.";
		} elseif ($row->status == 0 || ($row->status == 1 && $row->cmd == $cmd)) {
			$this->commandAlias->update($aliasObj);
			$this->commandAlias->activate($cmd, $alias);
			$msg = "Alias <highlight>{$alias}<end> for command <highlight>{$cmd}<end> added successfully.";
		} elseif ($row->status == 1 && $row->cmd != $cmd) {
			$msg = "Cannot add alias <highlight>{$alias}<end> since an alias with that name already exists.";
		} else {
			$msg = "Cannot add alias <highlight>{$alias}<end>.";
		}
		$context->reply($msg);
	}

	/**
	 * This command handler list all aliases.
	 *
	 * @HandlesCommand("alias")
	 */
	public function aliasListCommand(CmdContext $context, string $list="list"): void {
		$blob = "";
		/** @var array<string,CmdAlias[]> */
		$grouped = [];
		foreach ($this->commandAlias->getEnabledAliases() as $alias) {
			$key = explode(" ", $alias->cmd)[0];
			if (!isset($grouped[$key])) {
				$grouped[$key] = [];
			}
			$grouped[$key] []= $alias;
		}
		ksort($grouped);
		foreach ($grouped as $key => $aliases) {
			$blob .= "<header2>{$key}<end>\n";
			foreach ($aliases as $alias) {
				$removeLink = $this->text->makeChatcmd('Remove', "/tell <myname> alias rem {$alias->alias}");
				if ($alias->cmd === $key) {
					$blob .= "<tab>{$alias->alias} {$removeLink}\n";
				} else {
					$alias->cmd = implode(" ", array_slice(explode(" ", $alias->cmd), 1));
					$blob .= "<tab><highlight>{$alias->cmd}<end>: {$alias->alias} $removeLink\n";
				}
			}
		}

		$msg = $this->text->makeBlob('Alias List', $blob);
		$context->reply($msg);
	}

	/**
	 * This command handler remove a command alias.
	 *
	 * @HandlesCommand("alias")
	 */
	public function aliasRemCommand(CmdContext $context, PRemove $rem, string $alias): void {
		$alias = strtolower($alias);

		$row = $this->commandAlias->get($alias);
		if ($row === null || $row->status !== 1) {
			$msg = "Could not find alias <highlight>{$alias}<end>!";
		} else {
			$row->status = 0;
			$this->commandAlias->update($row);
			$this->commandAlias->deactivate($alias);

			$msg = "Alias <highlight>{$alias}<end> removed successfully.";
		}
		$context->reply($msg);
	}
}
