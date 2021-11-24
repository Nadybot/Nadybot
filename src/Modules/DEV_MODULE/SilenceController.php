<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	CommandManager,
	Event,
	CommandReply,
	DB,
	DBSchema\CmdCfg,
	LoggerWrapper,
	Text,
};
use Nadybot\Core\ParamClass\PWord;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'silence',
 *		accessLevel = 'mod',
 *		description = 'Silence commands in a particular channel',
 *		help        = 'silence.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'unsilence',
 *		accessLevel = 'mod',
 *		description = 'Unsilence commands in a particular channel',
 *		help        = 'silence.txt'
 *	)
 */
class SilenceController {

	public const DB_TABLE = "silence_cmd_<myname>";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Logger */
	public LoggerWrapper $logger;

	public const NULL_COMMAND_HANDLER = "SilenceController.nullCommand";

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
	}

	/**
	 * @HandlesCommand("silence")
	 */
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

	/**
	 * @HandlesCommand("silence")
	 */
	public function silenceAddCommand(CmdContext $context, string $command, PWord $channel): void {
		$command = strtolower($command);
		$channel = strtolower($channel());
		if ($channel === "org") {
			$channel = "guild";
		} elseif ($channel === "tell") {
			$channel = "msg";
		}

		$data = $this->commandManager->get($command, $channel);
		if (count($data) === 0) {
			$msg = "Could not find command <highlight>$command<end> for channel <highlight>$channel<end>.";
		} elseif ($this->isSilencedCommand($data[0])) {
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has already been silenced.";
		} else {
			$this->addSilencedCommand($data[0]);
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has been silenced.";
		}
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("unsilence")
	 */
	public function unsilenceAddCommand(CmdContext $context, string $command, PWord $channel): void {
		$command = strtolower($command);
		$channel = strtolower($channel());
		if ($channel === "org") {
			$channel = "guild";
		} elseif ($channel === "tell") {
			$channel = "msg";
		}

		$data = $this->commandManager->get($command, $channel);
		if (count($data) === 0) {
			$msg = "Could not find command <highlight>$command<end> for channel <highlight>$channel<end>.";
		} elseif (!$this->isSilencedCommand($data[0])) {
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has not been silenced.";
		} else {
			$this->removeSilencedCommand($data[0]);
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has been unsilenced.";
		}
		$context->reply($msg);
	}

	public function nullCommand(CmdContext $context): void {
		$this->logger->log('DEBUG', "Silencing command '{$context->message}' for channel '{$context->channel}'");
	}

	public function addSilencedCommand(CmdCfg $row): void {
		$this->commandManager->activate($row->type, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
		$this->db->table(self::DB_TABLE)
			->insert([
				"cmd" => $row->cmd,
				"channel" => $row->type,
			]);
	}

	public function isSilencedCommand(CmdCfg $row): bool {
		return $this->db->table(self::DB_TABLE)
			->where("cmd", $row->cmd)
			->where("channel", $row->type)
			->exists();
	}

	public function removeSilencedCommand(CmdCfg $row): void {
		$this->commandManager->activate($row->type, $row->file, $row->cmd, $row->admin);
		$this->db->table(self::DB_TABLE)
			->where("cmd", $row->cmd)
			->where("channel", $row->type)
			->delete();
	}

	/**
	 * @Event("connect")
	 * @Description("Overwrite command handlers for silenced commands")
	 */
	public function overwriteCommandHandlersEvent(Event $eventObj): void {
		$this->db->table(self::DB_TABLE)
			->asObj(SilenceCmd::class)
			->each(function (SilenceCmd $row) {
				$this->commandManager->activate($row->channel, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
			});
	}
}
