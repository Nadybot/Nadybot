<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Exception;
use Nadybot\Core\{
	CmdContext,
	CommandManager,
	LoggerWrapper,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PFilename;

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
	 */
	public function logsCommand(CmdContext $context): void {
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
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("logs")
	 */
	public function logsFileCommand(CmdContext $context, PFilename $file, ?string $search): void {
		$filename = $this->logger->getLoggingDirectory() . DIRECTORY_SEPARATOR . $file();
		$readsize = $this->settingManager->getInt('max_blob_size') - 500;

		try {
			$lines = file($filename);
			if ($lines === false) {
				$context->reply("The file <highlight>{$filename}<end> doesn't exist.");
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
				if (isset($search)) {
					$contents = "Search: <highlight>{$search}<end>\n\n" . $contents;
				}
				$msg = $this->text->makeBlob($file(), $contents);
			}
		} catch (Exception $e) {
			$msg = "Error: " . $e->getMessage();
		}
		$context->reply($msg);
	}
}
