<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\SYSTEM;

use function Amp\ByteStream\splitLines;

use Amp\File\{Filesystem, FilesystemException};
use Amp\Http\Client\{
	HttpClientBuilder,
	Interceptor\SetRequestHeader,
	Request,
};
use Amp\Parallel\Worker\TaskFailureException;
use Exception;
use Monolog\{
	Formatter\JsonFormatter,
	Handler\AbstractHandler,
	Handler\StreamHandler,
	Logger,
	Processor\IntrospectionProcessor,
	Processor\PsrLogMessageProcessor,
};
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	CmdContext,
	CommandManager,
	LegacyLogger,
	LoggerWrapper,
	ModuleInstance,
	ParamClass\PFilename,
	ParamClass\PWord,
	SettingManager,
	Text,
};
use Revolt\EventLoop;
use Throwable;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "logs",
		accessLevel: "admin",
		description: "View bot logs",
	),
	NCA\DefineCommand(
		command: "loglevel",
		accessLevel: "admin",
		description: "Change loglevel for debugging",
	),
	NCA\DefineCommand(
		command: "debug",
		accessLevel: "admin",
		description: "Create debug logs for a command",
	)
]
class LogsController extends ModuleInstance {
	#[NCA\Logger]
	public LoggerWrapper $logger;
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private Filesystem $fs;

	/** View a list of log files */
	#[NCA\HandlesCommand("logs")]
	public function logsCommand(CmdContext $context): void {
		try {
			if (!$this->fs->exists($this->logger->getLoggingDirectory())) {
				$context->reply(
					"Your bot is either not configured to create log files, ".
					"lacks the logging directory, or has no permission to access it."
				);
				return;
			}
			$files = $this->fs->listFiles($this->logger->getLoggingDirectory());
		} catch (FilesystemException $e) {
			$prev = $e->getPrevious();
			if (isset($prev)) {
				$msg = $prev->getMessage();
				if ($prev instanceof TaskFailureException) {
					$msg = $prev->getOriginalMessage();
				}
				$context->reply($msg);
				return;
			}
			throw $e;
		}
		if (!count($files)) {
			$context->reply("Log Files (0)");
			return;
		}
		sort($files);
		$blob = '';
		foreach ($files as $file) {
			$fileLink  = $this->text->makeChatcmd($file, "/tell <myname> logs {$file}");
			$errorLink = $this->text->makeChatcmd("ERROR", "/tell <myname> logs {$file} ERROR");
			$chatLink  = $this->text->makeChatcmd("CHAT", "/tell <myname> logs {$file} CHAT");
			$blob .= "{$fileLink} [{$errorLink}] [{$chatLink}]\n";
		}

		$msg = $this->text->makeBlob('Log Files (' . count($files) . ')', $blob);
		$context->reply($msg);
	}

	/**
	 * View the content of a log file, optionally searching for text
	 *
	 * &lt;search&gt; is a regular expression (without delimiters) and case-insensitive
	 */
	#[NCA\HandlesCommand("logs")]
	public function logsFileCommand(CmdContext $context, PFilename $file, ?string $search): void {
		$filename = $this->logger->getLoggingDirectory() . DIRECTORY_SEPARATOR . $file();
		$readsize = ($this->settingManager->getInt('max_blob_size')??10000) - 500;

		try {
			if (!$this->fs->exists($filename)) {
				$context->reply("The file <highlight>{$filename}<end> doesn't exist.");
				return;
			}

			$handle = $this->fs->openFile($filename, "r");
			$reader = splitLines($handle);
			$lines = [];
			foreach ($reader as $line) {
				if (strlen($line) > 1000) {
					$line = substr($line, 0, 997) . "[…]";
				}
				$lines []= $line;
			}
			$handle->close();
			$searchFunc = function (string $line): bool {
				return true;
			};
			if (isset($search) && preg_match("/^[a-zA-Z0-9_-]+$/", $search)) {
				$searchFunc = function (string $line) use ($search): bool {
					return stripos($line, $search) !== false;
				};
			} elseif (isset($search)) {
				$searchFunc = function (string $line) use ($search): bool {
					return preg_match(chr(1) . $search . chr(1) ."i", $line) === 1;
				};
			}
			$lines = array_reverse($lines);
			$contents = '';
			$trace = [];
			foreach ($lines as $line) {
				if (isset($search) && !$searchFunc($line)) {
					if (preg_match("/^(#\d+\s|\[stacktrace\])/", $line)) {
						array_unshift($trace, "<tab>{$line}");
					} else {
						$trace = [];
					}
					continue;
				}
				if (count($trace)) {
					$line .= "\n" . join("\n", $trace);
				}
				$line .= "\n";
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

	/** View the current log levels */
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

	/** Reset your temporarily changed loglevels back to your configuration */
	#[NCA\HandlesCommand("loglevel")]
	public function loglevelResetCommand(
		CmdContext $context,
		#[NCA\Str("reset")]
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

	/** Temporarily change the log level of the loggers matching &lt;mask&gt; */
	#[NCA\HandlesCommand("loglevel")]
	#[NCA\Help\Example("<symbol>loglevel * warning")]
	#[NCA\Help\Example("<symbol>loglevel RELAY_MODULE/* debug")]
	#[NCA\Help\Example("<symbol>loglevel RELAY_MODULE/RelayProtocol/* debug")]
	#[NCA\Help\Example("<symbol>loglevel Core/Nadybot info")]
	public function loglevelFileCommand(
		CmdContext $context,
		PWord $mask,
		#[NCA\StrChoice("debug", "info", "notice", "warning", "error", "emergency", "alert")]
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

	/** Debug a single command execution and upload the logs for inspection */
	#[NCA\HandlesCommand("debug")]
	#[NCA\Help\Example("<symbol>debug whois nady")]
	public function debugCommand(
		CmdContext $context,
		string $command
	): void {
		$newContext = clone $context;
		$newContext->message = $command;
		$loggers = LegacyLogger::getLoggers();
		$formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_JSON, true, true);
		$formatter->includeStacktraces(true);
		$debugFile = sys_get_temp_dir() . "/{$this->config->main->character}.debug.json";
		$this->fs->deleteFile($debugFile);
		$handler = new StreamHandler($debugFile, Logger::DEBUG, true, 0600);
		$handler->setFormatter($formatter);
		$processor = new IntrospectionProcessor(Logger::DEBUG, [], 1);
		$handler->pushProcessor($processor);
		$processor = new PsrLogMessageProcessor(null, false);
		$handler->pushProcessor($processor);
		foreach ($loggers as $logger) {
			$logger->pushHandler($handler);
		}
		$newContext->registerShutdownFunction(function () use ($context, $debugFile): void {
			$loggers = LegacyLogger::getLoggers();
			foreach ($loggers as $logger) {
				$logger->popHandler();
				$logger->popHandler();
			}
			EventLoop::defer(function (string $token) use ($context, $debugFile): void {
				$this->uploadDebugLog($context, $debugFile);
			});
		});

		$this->commandManager->processCmd($newContext);
	}

	public function uploadDebugLog(CmdContext $context, string $filename): void {
		try {
			$content = $this->fs->read($filename);
			$this->fs->deleteFile($filename);
		} catch (FilesystemException $e) {
			$context->reply("Unable to open <highlight>{$filename}<end>: " . $e->getMessage() . ".");
			return;
		}
		$content = str_replace('"' . BotRunner::getBasedir() . "/", "", $content);
		$boundary = '--------------------------'.microtime(true);
		$client = $this->builder
			->intercept(new SetRequestHeader("Authorization", "dRtXBMRnAH6AX2lx5ESiAQ=="))
			->intercept(new SetRequestHeader("Content-Type", "multipart/form-data; boundary={$boundary}"))
			->build();
		$postData = "--{$boundary}\r\n".
			"Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filename) . "\"\r\n".
			"Content-Type: application/json\r\n\r\n".
			$content . "\r\n".
			"--{$boundary}--\r\n";
		$request = new Request("https://debug.nadybot.org", "POST", $postData);
		try {
			$response = $client->request($request);
		} catch (Throwable $e) {
			$context->reply("Error uploading debug file: " . $e->getMessage());
			return;
		}
		if ($response->getStatus() !== 200) {
			$context->reply(
				"Error uploading debug file. ".
				"Code " . $response->getStatus(). " (".
				$response->getReason() . ")"
			);
			return;
		}
		try {
			$body = $response->getBody()->buffer();
		} catch (Throwable $e) {
			$context->reply("Error uploading debug file: " . $e->getMessage());
			return;
		}
		if ($body === '') {
			$context->reply("The file was uploaded successfully, but we did not receive a storage link.");
			return;
		}
		$url = trim($body);
		$context->reply(
			"The debug log has been uploaded successfully to <highlight>{$url}<end>."
		);
	}
}
