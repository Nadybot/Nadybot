<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Exception;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Nadybot\Core\{
	CmdContext,
	CommandManager,
	LegacyLogger,
	LoggerWrapper,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\ParamClass\PFilename;
use Nadybot\Core\ParamClass\PWord;

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
 *	@DefineCommand(
 *		command       = 'loglevel',
 *		accessLevel   = 'admin',
 *		description   = 'Change loglevel for debugging',
 *		help          = 'logs.txt'
 *	)
 *	@DefineCommand(
 *		command       = 'debug',
 *		accessLevel   = 'admin',
 *		description   = 'Create debug logs for a command',
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
		$readsize = ($this->settingManager->getInt('max_blob_size')??10000) - 500;

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
					if (preg_match("/^(#\d+\s|\[stacktrace\])/", $line)) {
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

	/**
	 * @HandlesCommand("loglevel")
	 */
	public function loglevelCommand(CmdContext $context): void {
		$loggers = LegacyLogger::getLoggers();
		$names = [];
		foreach ($loggers as $logger) {
			foreach ($logger->getHandlers() as $handler) {
				if ($handler instanceof AbstractHandler) {
					$names[$logger->getName()] = $logger->getLevelName($handler->getLevel());
				}
			}
		}
		if (empty($names)) {
			$context->reply("No loggers configured.");
			return;
		}
		ksort($names);
		$blob = "<header2>Configured loggers<end>";
		foreach ($names as $name => $logLevel) {
			$blob .= "\n<tab>- {$name}: <highlight>{$logLevel}<end>";
		}
		$msg = $this->text->makeBlob(
			"Configured loggers (" . count($names) . ")",
			$blob
		);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("loglevel")
	 * @Mask $action reset
	 */
	public function loglevelResetCommand(
		CmdContext $context,
		string $action
	): void {
		$loggers = LegacyLogger::getLoggers();
		LegacyLogger::getConfig(true);
		$names = [];
		foreach ($loggers as $logger) {
			$changes = LegacyLogger::assignLogLevel($logger);
			if (isset($changes)) {
				$names[$logger->getName()] = $changes;
			}
		}
		if (empty($names)) {
			$context->reply("No loggers needed changing.");
			return;
		}
		ksort($names);
		$numChanged = count($names);
		$blob = "<header2>Loggers changed<end>";
		foreach ($names as $name => $changes) {
			$blob .= "\n<tab>- {$name}: <highlight>{$changes[0]} -> {$changes[1]}<end>";
		}
		$msg = $this->text->blobWrap(
			"Changed ",
			$this->text->makeBlob(
				"{$numChanged} " . $this->text->pluralize("logger", $numChanged),
				$blob
			)
		);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("loglevel")
	 * @Mask $loglevel (debug|info|notice|warning|error|emergency|alert)
	 */
	public function loglevelFileCommand(
		CmdContext $context,
		PWord $mask,
		string $logLevel
	): void {
		$logLevel = strtoupper($logLevel);
		$loggers = LegacyLogger::getLoggers();
		LegacyLogger::tempLogLevelOrderride($mask(), $logLevel);
		$names = [];
		foreach ($loggers as $logger) {
			$changes = LegacyLogger::assignLogLevel($logger);
			if (isset($changes)) {
				$names[$logger->getName()] = $changes;
			}
		}
		if (empty($names)) {
			$context->reply("No loggers matching <highlight>'{$mask}'<end> that need changing.");
			return;
		}
		ksort($names);
		$numChanged = count($names);
		$blob = "<header2>Loggers changed<end>";
		foreach ($names as $name => $changes) {
			$blob .= "\n<tab>- {$name}: <highlight>{$changes[0]} -> {$changes[1]}<end>";
		}
		$msg = $this->text->blobWrap(
			"Changed ",
			$this->text->makeBlob(
				"{$numChanged} " . $this->text->pluralize("logger", $numChanged),
				$blob
			),
			($mask() !== '*') ? " matching <highlight>'{$mask}'<end>." : ""
		);
		$context->reply($msg);
	}

	/**
	 * @HandlesCommand("debug")
	 */
	public function debugCommand(
		CmdContext $context,
		string $command
	): void {
		$newContext = clone $context;
		$newContext->message = $command;
		$loggers = LegacyLogger::getLoggers();
		LegacyLogger::tempLogLevelOrderride("*", "debug");
		foreach ($loggers as $logger) {
			LegacyLogger::assignLogLevel($logger);
		}
		$context->registerShutdownFunction(function(): void {
			$loggers = LegacyLogger::getLoggers();
			LegacyLogger::getConfig(true);
			foreach ($loggers as $logger) {
				LegacyLogger::assignLogLevel($logger);
			}
		});

		$this->commandManager->processCmd($newContext);
	}
}
