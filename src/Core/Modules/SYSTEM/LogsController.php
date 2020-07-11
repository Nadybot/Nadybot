<?php

namespace Budabot\Core\Modules\SYSTEM;

use Exception;

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
	public $moduleName;

	/**
	 * @var \Budabot\Core\CommandManager $commandManager
	 * @Inject
	 */
	public $commandManager;

	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
	}

	/**
	 * @HandlesCommand("logs")
	 * @Matches("/^logs$/i")
	 */
	public function logsCommand($message, $channel, $sender, $sendto, $args) {
		$files = $this->util->getFilesInDirectory($this->logger->getLoggingDirectory());
		sort($files);
		$blob = '';
		foreach ($files as $file) {
			$file_link = $this->text->makeChatcmd($file, "/tell <myname> logs $file");
			$errorLink = $this->text->makeChatcmd("ERROR", "/tell <myname> logs $file ERROR");
			$chatLink = $this->text->makeChatcmd("CHAT", "/tell <myname> logs $file CHAT");
			$blob .= "$file_link [$errorLink] [$chatLink] \n";
		}

		$msg = $this->text->makeBlob('Log Files', $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("logs")
	 * @Matches("/^logs ([a-zA-Z0-9-_\.]+)$/i")
	 * @Matches("/^logs ([a-zA-Z0-9-_\.]+) (.+)$/i")
	 */
	public function logsFileCommand($message, $channel, $sender, $sendto, $args) {
		$filename = $this->logger->getLoggingDirectory() . "/" . $args[1];
		$readsize = $this->settingManager->get('max_blob_size') - 500;

		try {
			if (isset($args[2])) {
				$search = $args[2];
			} else {
				$search = ' ';
			}
			$fileContents = file_get_contents($filename);
			preg_match_all("/.*({$search}).*/i", $fileContents, $matches);
			$matches = array_reverse($matches[0]);
			$contents = '';
			foreach ($matches as $line) {
				if (strlen($contents . $line) > $readsize) {
					break;
				}
				$contents .= $line . "\n";
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
