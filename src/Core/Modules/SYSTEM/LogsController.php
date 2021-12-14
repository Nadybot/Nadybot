<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use Nadybot\Core\Attributes as NCA;
use Exception;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Nadybot\Core\{
	BotRunner,
	CmdContext,
	CommandManager,
	DedupHandler,
	Http,
	HttpResponse,
	LegacyLogger,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
};
use Nadybot\Core\ParamClass\PFilename;
use Nadybot\Core\ParamClass\PWord;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "logs",
		accessLevel: "admin",
		description: "View bot logs",
		help: "logs.txt"
	),
	NCA\DefineCommand(
		command: "loglevel",
		accessLevel: "admin",
		description: "Change loglevel for debugging",
		help: "debug.txt"
	),
	NCA\DefineCommand(
		command: "debug",
		accessLevel: "admin",
		description: "Create debug logs for a command",
		help: "debug.txt"
	)
]
class LogsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\HandlesCommand("logs")]
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

	#[NCA\HandlesCommand("logs")]
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

	#[NCA\HandlesCommand("loglevel")]
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

	#[NCA\HandlesCommand("loglevel")]
	public function loglevelResetCommand(
		CmdContext $context,
		#[NCA\Str("reset")] string $action
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

	#[NCA\HandlesCommand("loglevel")]
	public function loglevelFileCommand(
		CmdContext $context,
		PWord $mask,
		#[NCA\Regexp("debug|info|notice|warning|error|emergency|alert")]
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

	#[NCA\HandlesCommand("debug")]
	public function debugCommand(
		CmdContext $context,
		string $command
	): void {
		$newContext = clone $context;
		$newContext->message = $command;
		$loggers = LegacyLogger::getLoggers();
		$formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, true);
		$formatter->includeStacktraces(true);
		$debugFile = sys_get_temp_dir() . "/{$this->chatBot->char->name}.debug.json";
		@unlink($debugFile);
		$handler = new StreamHandler($debugFile, Logger::DEBUG, true, 0600);
		$handler->setFormatter($formatter);
		$processor = new IntrospectionProcessor(Logger::DEBUG, [], 1);
		$handler->pushProcessor($processor);
		$processor = new PsrLogMessageProcessor(null, false);
		$handler->pushProcessor($processor);
		foreach ($loggers as $logger) {
			$logger->pushHandler($handler);
			$logger->pushHandler(new DedupHandler());
		}
		$newContext->registerShutdownFunction(function() use ($context, $debugFile): void {
			$loggers = LegacyLogger::getLoggers();
			foreach ($loggers as $logger) {
				$logger->popHandler();
				$logger->popHandler();
			}
			$this->timer->callLater(0, [$this, "uploadDebugLog"], $context, $debugFile);
		});

		$this->commandManager->processCmd($newContext);
	}

	public function uploadDebugLog(CmdContext $context, string $filename): void {
		$content = file_get_contents($filename);
		if ($content === false) {
			$context->reply("Unable to open <highlight>{$filename}<end>.");
			return;
		}
		$content = str_replace('"' . BotRunner::getBasedir() . "/", "", $content);
		$boundary = '--------------------------'.microtime(true);
		$this->http->post("https://debug.nadybot.org")
			->withHeader("Authorization", "dRtXBMRnAH6AX2lx5ESiAQ==")
			->withHeader("Content-Type", "multipart/form-data; boundary={$boundary}")
			->withPostData(
				"--{$boundary}\r\n".
				"Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filename) . "\"\r\n".
				"Content-Type: application/json\r\n\r\n".
				$content . "\r\n".
				"--{$boundary}--\r\n"
			)
			->withCallback([$this, "handleDebugLogUpload"], $context);
			@unlink($filename);
	}

	public function handleDebugLogUpload(HttpResponse $response, CmdContext $context): void {
		if (isset($response->error)) {
			$context->reply("Error uploading debug file: {$response->error}");
			return;
		}
		if ($response->headers["status-code"] !== "200") {
			$context->reply(
				"Error uploading debug file. ".
				"Code {$response->headers['status-code']} (".
				($response->headers["status-message"] ?? "Unknown") . ")"
			);
			return;
		}
		if (!isset($response->body)) {
			$context->reply("The file was uploaded successfully, but we did receive a storage link.");
			return;
		}
		$url = trim($response->body);
		$context->reply(
			"The debug log has been uploaded successfully to <highlight>{$url}<end>."
		);
	}
}
