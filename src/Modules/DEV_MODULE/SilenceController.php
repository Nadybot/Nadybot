<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\{
	CommandManager,
	Event,
	CommandReply,
	DB,
	DBSchema\CmdCfg,
	Text,
};

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
	
	public const NULL_COMMAND_HANDLER = "SilenceController.nullCommand";
	
	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "silence_cmd");
	}
	
	/**
	 * @HandlesCommand("silence")
	 * @Matches("/^silence$/i")
	 */
	public function silenceCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT * FROM silence_cmd_<myname> ORDER BY cmd, channel";
		/** @var SilenceCmd[] */
		$data = $this->db->fetchAll(SilenceCmd::class, $sql);
		if (count($data) === 0) {
			$msg = "No commands have been silenced.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		foreach ($data as $row) {
			$unsilenceLink = $this->text->makeChatcmd("Unsilence", "/tell <myname> unsilence $row->cmd $row->channel");
			$blob .= "<highlight>$row->cmd<end> ($row->channel) - $unsilenceLink\n";
		}
		$msg = $this->text->makeBlob("Silenced Commands", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("silence")
	 * @Matches("/^silence (.+) (.+)$/i")
	 */
	public function silenceAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$command = strtolower($args[1]);
		$channel = strtolower($args[2]);
		
		$data = $this->commandManager->get($command, $channel);
		if (count($data) == 0) {
			$msg = "Could not find command <highlight>$command<end> for channel <highlight>$channel<end>.";
		} elseif ($this->isSilencedCommand($data[0])) {
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has already been silenced.";
		} else {
			$this->addSilencedCommand($data[0]);
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has been silenced.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("unsilence")
	 * @Matches("/^unsilence (.+) (.+)$/i")
	 */
	public function unsilenceCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$command = strtolower($args[1]);
		$channel = strtolower($args[2]);
		
		$data = $this->commandManager->get($command, $channel);
		if (count($data) === 0) {
			$msg = "Could not find command <highlight>$command<end> for channel <highlight>$channel<end>.";
		} elseif (!$this->isSilencedCommand($data[0])) {
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has not been silenced.";
		} else {
			$this->removeSilencedCommand($data[0]);
			$msg = "Command <highlight>$command<end> for channel <highlight>$channel<end> has been unsilenced.";
		}
		$sendto->reply($msg);
	}
	
	public function nullCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->logger->log('DEBUG', "Silencing command '$message' for channel '$channel'");
	}
	
	public function addSilencedCommand(CmdCfg $row) {
		$this->commandManager->activate($row->type, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
		$sql = "INSERT INTO silence_cmd_<myname> (cmd, channel) VALUES (?, ?)";
		$this->db->exec($sql, $row->cmd, $row->type);
	}
	
	public function isSilencedCommand(CmdCfg $row): bool {
		$sql = "SELECT * FROM silence_cmd_<myname> WHERE cmd = ? AND channel = ?";
		/** @var ?SilenceCmd */
		$row = $this->db->fetch(SilenceCmd::class, $sql, $row->cmd, $row->type);
		return $row !== null;
	}
	
	public function removeSilencedCommand(CmdCfg $row) {
		$this->commandManager->activate($row->type, $row->file, $row->cmd, $row->admin);
		$sql = "DELETE FROM silence_cmd_<myname> WHERE cmd = ? AND channel = ?";
		$this->db->exec($sql, $row->cmd, $row->type);
	}

	/**
	 * @Event("connect")
	 * @Description("Overwrite command handlers for silenced commands")
	 */
	public function overwriteCommandHandlersEvent(Event $eventObj): void {
		$sql = "SELECT * FROM silence_cmd_<myname>";
		/** @var SilenceCmd[] */
		$data = $this->db->fetchAll(SilenceCmd::class, $sql);
		foreach ($data as $row) {
			$this->commandManager->activate($row->channel, self::NULL_COMMAND_HANDLER, $row->cmd, 'all');
		}
	}
}
