<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	CommandAlias,
	CommandManager,
	CommandReply,
	Text,
};
use Nadybot\Core\DBSchema\CmdAlias;

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
	 * This command handler add a command alias.
	 *
	 * @HandlesCommand("alias")
	 * @Matches("/^alias add ([a-z0-9]+) (.+)/si")
	 */
	public function aliasAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$alias = strtolower($args[1]);
		$cmd = $args[2];
	
		$alias_obj = new CmdAlias();
		$alias_obj->module = '';
		$alias_obj->cmd = $cmd;
		$alias_obj->alias = $alias;
		$alias_obj->status = 1;
	
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
			$this->commandAlias->add($alias_obj);
			$this->commandAlias->activate($cmd, $alias);
			$msg = "Alias <highlight>{$alias}<end> for command <highlight>{$cmd}<end> added successfully.";
		} elseif ($row->status == 0 || ($row->status == 1 && $row->cmd == $cmd)) {
			$this->commandAlias->update($alias_obj);
			$this->commandAlias->activate($cmd, $alias);
			$msg = "Alias <highlight>{$alias}<end> for command <highlight>{$cmd}<end> added successfully.";
		} elseif ($row->status == 1 && $row->cmd != $cmd) {
			$msg = "Cannot add alias <highlight>{$alias}<end> since an alias with that name already exists.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler list all aliases.
	 *
	 * @HandlesCommand("alias")
	 * @Matches("/^alias list$/i")
	 */
	public function aliasListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
			$blob .= "<header2>$key<end>\n";
			foreach ($aliases as $alias) {
				$removeLink = $this->text->makeChatcmd('Remove', "/tell <myname> alias rem {$alias->alias}");
				if ($alias->cmd === $key) {
					$blob .= "<tab>{$alias->alias} $removeLink\n";
				} else {
					$alias->cmd = implode(" ", array_slice(explode(" ", $alias->cmd), 1));
					$blob .= "<tab><highlight>{$alias->cmd}<end>: {$alias->alias} $removeLink\n";
				}
			}
		}
	
		$msg = $this->text->makeBlob('Alias List', $blob);
		$sendto->reply($msg);
	}

	/**
	 * This command handler remove a command alias.
	 *
	 * @HandlesCommand("alias")
	 * @Matches("/^alias rem ([a-z0-9]+)/i")
	 */
	public function aliasRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$alias = strtolower($args[1]);
	
		$row = $this->commandAlias->get($alias);
		if ($row === null || $row->status != 1) {
			$msg = "Could not find alias <highlight>{$alias}<end>!";
		} else {
			$row->status = 0;
			$this->commandAlias->update($row);
			$this->commandAlias->deactivate($alias);
	
			$msg = "Alias <highlight>{$alias}<end> removed successfully.";
		}
		$sendto->reply($msg);
	}
}
