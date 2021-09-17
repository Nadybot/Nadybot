<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Exception;
use Nadybot\Core\{
	CommandManager,
	CommandReply,
	LoggerWrapper,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command       = 'logs',
 *		accessLevel   = 'admin',
 *		description   = 'View bot logs',
 *		help          = 'logs.txt'
 *	)
 */
class LogsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @HandlesCommand("logs")
	 * @Matches("/^logs$/i")
	 */
	public function logsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$files = $this->util->getFilesInDirectory(
			$this->logger->getLoggingDirectory()
		);
		sort($files);
		$blob = '';
		foreach ($files as $file) {
			$fileLink  = $this->text->makeChatcmd($file, "/tell <myname> logs $file");
			$errorLink = $this->text->makeChatcmd("ERROR", "/tell <myname> logs $file ERROR");
			$chatLink  = $this->text->makeChatcmd("CHAT", "/tell <myname> logs $file CHAT");
			$blob .= "$fileLink [$errorLink] [$chatLink]\n";
		}

		$msg = $this->text->makeBlob('Log Files', $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("logs")
	 * @Matches("/^logs ([a-zA-Z0-9-_\.]+)$/i")
	 * @Matches("/^logs ([a-zA-Z0-9-_\.]+) (.+)$/i")
	 */
	public function logsFileCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$filename = $this->logger->getLoggingDirectory() . DIRECTORY_SEPARATOR . $args[1];
		$readsize = $this->settingManager->getInt('max_blob_size') - 500;

		try {
			$search = $args[2] ?? null;
			$lines = file($filename);
			if ($lines === false) {
				$sendto->reply("The file <highlight>{$filename}<end> doesn't exist.");
				return;
			}
			$lines = array_reverse($lines);
			$contents = '';
			$trace = [];
			foreach ($lines as $line) {
				if (isset($search) && !preg_match(chr(1) . $search . chr(1) ."i", $line)) {
					if (preg_match("/^#\d+\s/", $line)) {
						array_unshift($trace, "<tab>$line");
					} else {
						$trace = [];
					}
					continue;
				}
				$line .= join("", $trace);
				$trace = [];
				if (strlen($contents . $line) > $readsize) {
					break;
				}
				$contents .= $line;
			}

			if (empty($contents)) {
				$msg = "File is empty or nothing matched your search criteria.";
			} else {
				if (isset($args[2])) {
					$contents = "Search: $args[2]\n\n" . $contents;
				}
				$msg = $this->text->makeBlob($args[1], $contents);
			}
		} catch (Exception $e) {
			$msg = "Error: " . $e->getMessage();
		}
		$sendto->reply($msg);
	}
}
