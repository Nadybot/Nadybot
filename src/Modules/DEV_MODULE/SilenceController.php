<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Events\ConnectEvent;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	DB,
	DBSchema\CmdCfg,
	ModuleInstance,
	Text,
};
use Psr\Log\LoggerInterface;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'silence',
		accessLevel: 'mod',
		description: 'Silence commands in a particular permission set',
	),
	NCA\DefineCommand(
		command: 'unsilence',
		accessLevel: 'mod',
		description: 'Unsilence commands in a particular permission set',
	)
]
class SilenceController extends ModuleInstance {
	public const NULL_COMMAND_HANDLER = 'SilenceController.nullCommand';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private CommandManager $commandManager;

	/** Get a list of all commands that have been silenced */
	#[NCA\HandlesCommand('silence')]
	public function silenceCommand(CmdContext $context): void {
		$data = $this->db->table(SilenceCmd::getTable())
			->orderBy('cmd')
			->orderBy('channel')
			->asObj(SilenceCmd::class);
		if ($data->count() === 0) {
			$msg = 'No commands have been silenced.';
			$context->reply($msg);
			return;
		}
		$blob = $data->reduce(static function (string $blob, SilenceCmd $row): string {
			$unsilenceLink = Text::makeChatcmd('Unsilence', "/tell <myname> unsilence {$row->cmd} {$row->channel}");
			return "{$blob}<highlight>{$row->cmd}<end> ({$row->channel}) - {$unsilenceLink}\n";
		}, '');
		$msg = Text::makeBlob('Silenced Commands', $blob);
		$context->reply($msg);
	}

	/** Silence a command for a specific permission set */
	#[NCA\HandlesCommand('silence')]
	public function silenceAddCommand(
		CmdContext $context,
		string $command,
		#[NCA\Parameter\WordStr] string $permissionSet
	): void {
		$command = strtolower($command);
		$permissionSet = strtolower($permissionSet);

		$cmdCfg = $this->commandManager->get($command);
		if (!isset($cmdCfg) || !isset($cmdCfg->permissions[$permissionSet]) || !$cmdCfg->permissions[$permissionSet]->enabled) {
			$msg = "Could not find command <highlight>{$command}<end> for channel <highlight>{$permissionSet}<end>.";
		} elseif ($this->isSilencedCommand($cmdCfg, $permissionSet)) {
			$msg = "Command <highlight>{$command}<end> for channel <highlight>{$permissionSet}<end> has already been silenced.";
		} else {
			$this->addSilencedCommand($cmdCfg, $permissionSet);
			$msg = "Command <highlight>{$command}<end> for channel <highlight>{$permissionSet}<end> has been silenced.";
		}
		$context->reply($msg);
	}

	/** Un-silence a command for a specific permission set */
	#[NCA\HandlesCommand('unsilence')]
	public function unsilenceAddCommand(
		CmdContext $context,
		string $command,
		#[NCA\Parameter\WordStr] string $permissionSet
	): void {
		$command = strtolower($command);
		$permissionSet = strtolower($permissionSet);

		$cmdCfg = $this->commandManager->get($command);
		if (!isset($cmdCfg) || !isset($cmdCfg->permissions[$permissionSet]) || !$cmdCfg->permissions[$permissionSet]->enabled) {
			$msg = "Could not find command <highlight>{$command}<end> for channel <highlight>{$permissionSet}<end>.";
		} elseif (!$this->isSilencedCommand($cmdCfg, $permissionSet)) {
			$msg = "Command <highlight>{$command}<end> for channel <highlight>{$permissionSet}<end> has not been silenced.";
		} else {
			$this->removeSilencedCommand($cmdCfg, $permissionSet);
			$msg = "Command <highlight>{$command}<end> for channel <highlight>{$permissionSet}<end> has been unsilenced.";
		}
		$context->reply($msg);
	}

	public function nullCommand(CmdContext $context): void {
		$this->logger->info("Silencing command '{command}' for permission set '{permission_set}'", [
			'command' => $context->message,
			'permission_set' => $context->permissionSet ?? '<all>',
		]);
	}

	public function addSilencedCommand(CmdCfg $row, string $channel): void {
		$this->commandManager->activate($channel, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
		$this->db->insert(new SilenceCmd(
			cmd: $row->cmd,
			channel: $channel,
		));
	}

	public function isSilencedCommand(CmdCfg $row, string $channel): bool {
		return $this->db->table(SilenceCmd::getTable())
			->where('cmd', $row->cmd)
			->where('channel', $channel)
			->exists();
	}

	public function removeSilencedCommand(CmdCfg $row, string $channel): void {
		$this->commandManager->activate($channel, $row->file, $row->cmd, $row->permissions[$channel]->access_level);
		$this->db->table(SilenceCmd::getTable())
			->where('cmd', $row->cmd)
			->where('channel', $channel)
			->delete();
	}

	#[NCA\HandlesEvent(
		name: ConnectEvent::EVENT_MASK,
		description: 'Overwrite command handlers for silenced commands'
	)]
	public function overwriteCommandHandlersEvent(ConnectEvent $eventObj): void {
		$this->db->table(SilenceCmd::getTable())
			->asObj(SilenceCmd::class)
			->each(function (SilenceCmd $row): void {
				$this->commandManager->activate($row->channel, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
			});
	}
}
