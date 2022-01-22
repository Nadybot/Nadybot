<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	CommandManager,
	Event,
	DB,
	DBSchema\CmdCfg,
	ModuleInstance,
	LoggerWrapper,
	Text,
};
use Nadybot\Core\ParamClass\PWord;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "silence",
		accessLevel: "mod",
		description: "Silence commands in a particular channel",
		help: "silence.txt"
	),
	NCA\DefineCommand(
		command: "unsilence",
		accessLevel: "mod",
		description: "Unsilence commands in a particular channel",
		help: "silence.txt"
	)
]
class SilenceController extends ModuleInstance {

	public const DB_TABLE = "silence_cmd_<myname>";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public const NULL_COMMAND_HANDLER = "SilenceController.nullCommand";

	#[NCA\Setup]
	public function setup(): void {
	}

	#[NCA\HandlesCommand("silence")]
	public function silenceCommand(CmdContext $context): void {
		/** @var Collection<SilenceCmd> */
		$data = $this->db->table(self::DB_TABLE)
			->orderBy("cmd")
			->orderBy("channel")
			->asObj(SilenceCmd::class);
		if ($data->count() === 0) {
			$msg = "No commands have been silenced.";
			$context->reply($msg);
			return;
		}
		$blob = $data->reduce(function(string $blob, SilenceCmd $row) {
			$unsilenceLink = $this->text->makeChatcmd("Unsilence", "/tell <myname> unsilence $row->cmd $row->channel");
			return "{$blob}<highlight>{$row->cmd}<end> ({$row->channel}) - {$unsilenceLink}\n";
		}, '');
		$msg = $this->text->makeBlob("Silenced Commands", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("silence")]
	public function silenceAddCommand(CmdContext $context, string $command, PWord $channel): void {
		$command = strtolower($command);
		$channel = strtolower($channel());

		$cmdCfg = $this->commandManager->get($command);
		if (!isset($cmdCfg) || !isset($cmdCfg->permissions[$channel]) || !$cmdCfg->permissions[$channel]->enabled) {
			$msg = "Could not find command <highlight>{$command}<end> for channel <highlight>{$channel}<end>.";
		} elseif ($this->isSilencedCommand($cmdCfg, $channel)) {
			$msg = "Command <highlight>{$command}<end> for channel <highlight>{$channel}<end> has already been silenced.";
		} else {
			$this->addSilencedCommand($cmdCfg, $channel);
			$msg = "Command <highlight>{$command}<end> for channel <highlight>{$channel}<end> has been silenced.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("unsilence")]
	public function unsilenceAddCommand(CmdContext $context, string $command, PWord $channel): void {
		$command = strtolower($command);
		$channel = strtolower($channel());

		$cmdCfg = $this->commandManager->get($command);
		if (!isset($cmdCfg) || !isset($cmdCfg->permissions[$channel]) || !$cmdCfg->permissions[$channel]->enabled) {
			$msg = "Could not find command <highlight>{$command}<end> for channel <highlight>{$channel}<end>.";
		} elseif (!$this->isSilencedCommand($cmdCfg, $channel)) {
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has not been silenced.";
		} else {
			$this->removeSilencedCommand($cmdCfg, $channel);
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has been unsilenced.";
		}
		$context->reply($msg);
	}

	public function nullCommand(CmdContext $context): void {
		$this->logger->info("Silencing command '{$context->message}' for channel '{$context->channel}'");
	}

	public function addSilencedCommand(CmdCfg $row, string $channel): void {
		$this->commandManager->activate($channel, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
		$this->db->table(self::DB_TABLE)
			->insert([
				"cmd" => $row->cmd,
				"channel" => $channel,
			]);
	}

	public function isSilencedCommand(CmdCfg $row, string $channel): bool {
		return $this->db->table(self::DB_TABLE)
			->where("cmd", $row->cmd)
			->where("channel", $channel)
			->exists();
	}

	public function removeSilencedCommand(CmdCfg $row, string $channel): void {
		$this->commandManager->activate($channel, $row->file, $row->cmd, $row->permissions[$channel]->access_level);
		$this->db->table(self::DB_TABLE)
			->where("cmd", $row->cmd)
			->where("channel", $channel)
			->delete();
	}

	#[NCA\Event(
		name: "connect",
		description: "Overwrite command handlers for silenced commands"
	)]
	public function overwriteCommandHandlersEvent(Event $eventObj): void {
		$this->db->table(self::DB_TABLE)
			->asObj(SilenceCmd::class)
			->each(function (SilenceCmd $row): void {
				$this->commandManager->activate($row->channel, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
			});
	}
}
