<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONSOLE;

use function Amp\async;
use function Safe\{readline_add_history, readline_callback_handler_install, readline_read_history, readline_write_history};

use Amp\File\Filesystem;
use Exception;

use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	Channels\ConsoleChannel,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	MessageHub,
	ModuleInstance,
	Nadybot,
	Registry,
	Routing\RoutableMessage,
	Routing\Source,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

#[NCA\Instance]
class ConsoleController extends ModuleInstance {
	/** Use ANSI colors */
	#[NCA\Setting\Boolean] public bool $consoleColor = false;

	/** Set background color */
	#[NCA\Setting\Boolean] public bool $consoleBGColor = false;

	/**
	 * @var resource
	 *
	 * @psalm-var resource|closed-resource
	 */
	public $socket;

	public bool $useReadline = false;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Filesystem $fs;

	private string $socketHandle;

	#[NCA\Setup]
	public function setup(): void {
		if (!$this->config->general->enableConsoleClient || BotRunner::isWindows()) {
			return;
		}
		$this->commandManager->registerSource("console");
		$handler = new ConsoleCommandReply($this->chatBot);
		Registry::injectDependencies($handler);
		$channel = new ConsoleChannel($handler);
		Registry::injectDependencies($channel);
		$this->messageHub->registerMessageReceiver($channel)
			->registerMessageEmitter($channel);
	}

	public function getCacheFile(): string {
		if (isset($_SERVER["XDG_CACHE_HOME"])) {
			return explode(":", $_SERVER["XDG_CACHE_HOME"])[0] . "/Nadybot/readline.history";
		}
		if (isset($_SERVER["HOME"])) {
			return $_SERVER["HOME"] . "/.cache/Nadybot/readline.history";
		}
		return sys_get_temp_dir() . "/Nadybot/readline.history";
	}

	public function loadHistory(): void {
		$file = $this->getCacheFile();
		if ($this->fs->exists($file)) {
			try {
				readline_read_history($file);
			} catch (Exception $e) {
				$this->logger->warning(
					"Unable to read the readline history file {file}: {error}",
					[
						"file" => $file,
						"error" => $e->getMessage(),
						"exception" => $e,
					]
				);
			}
		}
	}

	public function saveHistory(): void {
		$file = $this->getCacheFile();
		if (!$this->fs->exists($file)) {
			$this->fs->createDirectoryRecursively(dirname($file), 0700);
		}
		try {
			readline_write_history($file);
		} catch (Exception $e) {
			$this->logger->warning(
				"Unable to write the readline history file {file}: {error}",
				[
					"file" => $file,
					"error" => $e->getMessage(),
					"exception" => $e,
				]
			);
		}
	}

	/**
	 * This is an Event("connect") instead of Setup since you cannot use the console
	 * before the bot is fully ready anyway
	 */
	#[NCA\Event(
		name: "connect",
		description: "Initializes the console",
		defaultStatus: 1
	)]
	public function setupConsole(): void {
		if (!$this->config->general->enableConsoleClient) {
			return;
		}
		if (BotRunner::isWindows()) {
			$this->logger->warning('Console not available on Windows');
			return;
		}
		if (!stream_isatty(STDIN)) {
			$this->logger->warning('Stdin is not a TTY, console not available.');
			return;
		}
		$this->useReadline = function_exists('readline_callback_handler_install');
		if (!$this->useReadline) {
			$this->logger->warning('readline not supported on this platform, using basic console');
			$callback = function (string $handle, mixed $resource): void {
				$this->processStdin();
			};
		} else {
			$callback = function (string $handle, mixed $resource): void {
				readline_callback_read_char();
			};
		}
		$this->loadHistory();
		$this->socket = STDIN;
		EventLoop::delay(1, function (string $token) use ($callback): void {
			if (!is_resource($this->socket)) {
				return;
			}
			$this->logger->notice("StdIn console activated, accepting commands");
			$this->socketHandle = EventLoop::onReadable($this->socket, $callback);
			if ($this->useReadline) {
				readline_callback_handler_install('> ', fn (?string $line) => $this->processLine($line));
			} else {
				echo("> ");
			}
		});
	}

	/** Handle data arriving on stdin */
	public function processStdin(): void {
		if (!is_resource($this->socket)) {
			return;
		}
		// @phpstan-ignore-next-line
		if (feof($this->socket)) {
			echo("EOF received, closing console.\n");
		// @phpstan-ignore-next-line
			@fclose($this->socket);
			EventLoop::cancel($this->socketHandle);
			return;
		}
		// @phpstan-ignore-next-line
		$line = fgets($this->socket);
		if ($line !== false) {
			$this->processLine(trim($line));
			echo("> ");
		}
	}

	private function processLine(?string $line): void {
		if ($line === null || trim($line) === '') {
			if ($this->useReadline) {
				readline_callback_handler_install('> ', fn (?string $line) => $this->processLine($line));
			}
			return;
		}
		if ($this->useReadline) {
			readline_add_history($line);
			EventLoop::defer(function (string $token): void {
				$this->saveHistory();
			});
			readline_callback_handler_install('> ', fn (?string $line) => $this->processLine($line));
		}

		$context = new CmdContext($this->config->general->superAdmins[0]??"<no superadmin set>");
		$context->message = $line;
		$context->source = Source::CONSOLE;
		$context->sendto = new ConsoleCommandReply($this->chatBot);
		Registry::injectDependencies($context->sendto);
		async(function () use ($context): void {
			$uid = $this->chatBot->getUid($context->char->name);
			$context->char->id = $uid;
			$rMessage = new RoutableMessage($context->message);
			$rMessage->setCharacter($context->char);
			$rMessage->prependPath(new Source(Source::CONSOLE, "Console"));
			if ($this->messageHub->handle($rMessage) !== $this->messageHub::EVENT_DELIVERED) {
				$context->setIsDM(true);
			}

			$this->commandManager->checkAndHandleCmd($context);
		});
	}
}
