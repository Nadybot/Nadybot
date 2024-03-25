<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandManager,
	DBSchema\CmdAlias,
	ModuleInstance,
	ParamClass\PRemove,
	ParamClass\PWord,
	Text,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'alias',
		accessLevel: 'mod',
		description: 'Manage command aliases',
		defaultStatus: 1
	)
]
class AliasController extends ModuleInstance {
	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private Text $text;

	/** Add a new alias with spaces for a command */
	#[NCA\HandlesCommand('alias')]
	public function aliasAddCommand1(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		#[NCA\Regexp('"[a-z 0-9]+"', example: '&lt;"alias with spaces"&gt;')] string $alias,
		string $command
	): void {
		$this->aliasAddCommand($context, substr($alias, 1, -1), $command);
	}

	/** Add a new alias with spaces for a command */
	#[NCA\HandlesCommand('alias')]
	#[NCA\Help\Example(
		command: "<symbol>alias add 'raid shutdown' macro cmd That is all for today folks!|raid stop|kickall",
	)]
	public function aliasAddCommand2(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		#[NCA\Regexp("'[a-z 0-9]+'", example: "&lt;'alias with spaces'&gt;")] string $alias,
		string $command
	): void {
		$this->aliasAddCommand($context, substr($alias, 1, -1), $command);
	}

	/**
	 * Add a new alias for a command
	 *
	 * You can refer to the parameters of your command with a numeric placeholder {1} to whatever
	 * you want to go. The alias will throw an error, though, when you do not give enough
	 * arguments to the alias. If an alias defines a placeholder {4}, then you have to give
	 * at least 4 parameters. The highest parameter will always get all remaining parameters
	 * given to the alias and you can define default values like {3:Default value} if the
	 * parameter is not given. The placeholder {0} always contains all arguments as one.
	 */
	#[NCA\HandlesCommand('alias')]
	#[NCA\Help\Example(
		command: '<symbol>alias add o online',
		description: 'Lets you use <symbol>o instead of <symbol>online'
	)]
	#[NCA\Help\Example(
		command: '<symbol>alias add orgwins victory org Nadybot Testers',
		description: 'Lets you use <highlight><symbol>orgwins<end> instead of '.
			'<highlight><symbol>victory org Nadybot Testers<end> '.
			'to see recent tower victories of your org'
	)]
	#[NCA\Help\Example(
		command: '<symbol>alias add c cmd !!! {0} !!!',
		description: 'Encapsulate your commands into exclamation marks'
	)]
	#[NCA\Help\Example(
		command: '<symbol>alias add c cmd !!! {0:Party on guys} !!!',
		description: 'Same, but with a default text'
	)]
	public function aliasAddCommand3(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		PWord $alias,
		string $command
	): void {
		$this->aliasAddCommand($context, $alias(), $command);
	}

	public function aliasAddCommand(CmdContext $context, string $alias, string $cmd): void {
		$alias = strtolower($alias);

		$aliasObj = new CmdAlias(
			module: '',
			cmd: $cmd,
			alias: $alias,
			status: 1,
		);

		$command = $this->commandManager->get($alias);
		$enabled = false;
		if (isset($command)) {
			foreach ($command->permissions as $permission) {
				if ($permission->enabled) {
					$enabled = true;
					break;
				}
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

	/** List all currently defined aliases */
	#[NCA\HandlesCommand('alias')]
	public function aliasListCommand(CmdContext $context, #[NCA\Str('list')] string $action): void {
		$blob = '';

		/** @var array<string,CmdAlias[]> */
		$grouped = [];
		foreach ($this->commandAlias->getEnabledAliases() as $alias) {
			$key = explode(' ', $alias->cmd)[0];
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
					$alias->cmd = implode(' ', array_slice(explode(' ', $alias->cmd), 1));
					$blob .= "<tab><highlight>{$alias->cmd}<end>: {$alias->alias} {$removeLink}\n";
				}
			}
		}

		$msg = $this->text->makeBlob('Alias List', $blob);
		$context->reply($msg);
	}

	/** Remove a command alias */
	#[NCA\HandlesCommand('alias')]
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
