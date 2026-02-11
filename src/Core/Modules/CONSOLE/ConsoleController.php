<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONSOLE;

use function Safe\{readline_add_history, readline_callback_handler_install, readline_read_history, readline_write_history, stream_isatty};

use ErrorException;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	Channels\ConsoleChannel,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	Events\ConnectEvent,
	Filesystem,
	MessageHub,
	ModuleInstance,
	Nadybot,
	Registry,
	RouteResult,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	Types\Status,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Safe\Exceptions\{ReadlineException, StreamException};

#[NCA\Instance]
class ConsoleController extends ModuleInstance {
	public const PLACEHOLDERS = 'placeholders';

	/** Use ANSI colors */
	#[NCA\Setting\Boolean] public bool $consoleColor = false;

	/** Set background color */
	#[NCA\Setting\Boolean] public bool $consoleBGColor = false;

	/** How to display items and nanos */
	#[NCA\Setting\Template(
		exampleValues: [
			'id' => 12_345,
			'ql' => 200,
		],
		options: [
			'placeholders' => self::PLACEHOLDERS,
			'aoitems' => 'https://aoitems.com/item/{id}{?ql:/{ql}}',
			'auno' => 'https://auno.org/ao/db.php?id={id}{?ql:&ql={ql}}',
			'aogalaxy' => 'https://www.aogalaxy.com/_items/item.php?aoid={id}{?ql:&ql={ql}}',
		]
	)]
	public string $consoleItemDisplay = 'https://auno.org/ao/db.php?id={id}{?ql:&ql={ql}}';

	/**
	 * @var resource
	 *
	 * @psalm-var resource|closed-resource
	 */
	public mixed $socket;

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
		if (BotRunner::getArguments()->testRun) {
			return;
		}
		$this->commandManager->registerSource('console');
		$handler = new ConsoleCommandReply($this->chatBot);
		Registry::injectDependencies($handler);
		$channel = new ConsoleChannel($handler);
		Registry::injectDependencies($channel);
		$this->messageHub->registerMessageReceiver($channel)
			->registerMessageEmitter($channel);
	}

	public function getCacheFile(): string {
		if (isset($_SERVER['XDG_CACHE_HOME'])) {
			return explode(':', $_SERVER['XDG_CACHE_HOME'])[0] . '/Nadybot/readline.history';
		}
		if (isset($_SERVER['HOME'])) {
			return $_SERVER['HOME'] . '/.cache/Nadybot/readline.history';
		}
		return sys_get_temp_dir() . '/Nadybot/readline.history';
	}

	public function loadHistory(): void {
		if (!function_exists('readline_read_history')) {
			return;
		}
		$file = $this->getCacheFile();
		if ($this->fs->exists($file)) {
			try {
				readline_read_history($file);
			} catch (Exception $e) {
				$this->logger->warning(
					'Unable to read the readline history file {file}: {error}',
					[
						'file' => $file,
						'error' => $e->getMessage(),
						'exception' => $e,
					]
				);
			}
		}
	}

	public function saveHistory(): void {
		$file = $this->getCacheFile();
		if (!$this->fs->exists($file)) {
			$this->fs->createDirectoryRecursively(dirname($file), 0o700);
		}
		try {
			readline_write_history($file);
		} catch (ReadlineException $e) {
			$this->logger->warning(
				'Unable to write the readline history file {file}: {error}',
				[
					'file' => $file,
					'error' => $e->getMessage(),
					'exception' => $e,
				]
			);
		}
	}

	/** Initializes the console */
	#[NCA\HandlesEvent(defaultStatus: Status::Enabled)]
	public function setupConsole(ConnectEvent $event): void {
		if (!$this->config->general->enableConsoleClient) {
			return;
		}
		if (BotRunner::getArguments()->testRun) {
			$this->logger->warning('Console disabled during testing');
			return;
		}
		if (BotRunner::isWindows()) {
			$this->logger->warning('Console not available on Windows');
			return;
		}
		try {
			stream_isatty(\STDIN);
		} catch (StreamException) {
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
			$callback = static function (string $handle, mixed $resource): void {
				readline_callback_read_char();
			};
		}
		$this->loadHistory();
		$this->socket = \STDIN;
		EventLoop::delay(1, function (string $token) use ($callback): void {
			if (!is_resource($this->socket)) {
				return;
			}
			$this->logger->notice('StdIn console activated, accepting commands');
			$this->socketHandle = EventLoop::onReadable($this->socket, $callback);
			if ($this->useReadline) {
				readline_callback_handler_install('> ', $this->processLine(...));
			} else {
				echo('> ');
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
			try {
				// @phpstan-ignore-next-line
				Safe::exceptionWrapper(fclose(...), $this->socket);
			} catch (ErrorException) {
			}
			EventLoop::cancel($this->socketHandle);
			return;
		}
		// @phpstan-ignore-next-line
		$line = fgets($this->socket);
		if ($line !== false) {
			$this->processLine(trim($line));
			echo('> ');
		}
	}

	private function processLine(?string $line): void {
		if ($line === null || trim($line) === '') {
			if ($this->useReadline) {
				readline_callback_handler_install('> ', $this->processLine(...));
			}
			return;
		}
		if ($this->useReadline) {
			readline_add_history($line);
			EventLoop::queue($this->saveHistory(...));
			readline_callback_handler_install('> ', $this->processLine(...));
		}

		$sendto = new ConsoleCommandReply($this->chatBot);
		Registry::injectDependencies($sendto);
		$context = new CmdContext(
			charName: $this->config->general->superAdmins[0]??'<no superadmin set>',
			message: $line,
			source: Source::CONSOLE,
			sendto: $sendto,
		);
		EventLoop::queue($this->processContext(...), $context);
	}

	private function processContext(CmdContext $context): void {
		$uid = $this->chatBot->getUid($context->char->name);
		$context->char->id = $uid;
		$rMessage = new RoutableMessage($context->message);
		$rMessage->setCharacter($context->char);
		$rMessage->prependPath(new Source(Source::CONSOLE, 'Console'));
		if ($this->messageHub->handle($rMessage) !== RouteResult::Delivered) {
			$context->setIsDM(true);
		}

		$this->commandManager->checkAndHandleCmd($context);
	}
}
