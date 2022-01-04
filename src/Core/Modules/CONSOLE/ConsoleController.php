<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONSOLE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	BotRunner,
	CmdContext,
	CommandManager,
	ConfigFile,
	Instance,
	LoggerWrapper,
	MessageHub,
	Nadybot,
	Registry,
	SettingManager,
	SocketManager,
	SocketNotifier,
	Timer,
};
use Nadybot\Core\Channels\ConsoleChannel;

#[NCA\Instance]
class ConsoleController extends Instance {
		#[NCA\Inject]
	public SocketManager $socketManager;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public SocketNotifier $notifier;

	/**
	 * @var resource
	 * @psalm-var resource|closed-resource
	 */
	public $socket;

	public bool $useReadline = false;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: "console_color",
			description: "Use ANSI colors",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "console_bg_color",
			description: "Set background color",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
		if ($this->config->enableConsoleClient &&!BotRunner::isWindows()) {
			$handler = new ConsoleCommandReply($this->chatBot);
			Registry::injectDependencies($handler);
			$channel = new ConsoleChannel($handler);
			Registry::injectDependencies($channel);
			$this->messageHub->registerMessageReceiver($channel);
		}
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
		if (@file_exists($file)) {
			\Safe\readline_read_history($file);
		}
	}

	public function saveHistory(): void {
		$file = $this->getCacheFile();
		if (!@file_exists($file)) {
			\Safe\mkdir(dirname($file), 0700, true);
		}
		\Safe\readline_write_history($file);
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
		if (!$this->config->enableConsoleClient) {
			return;
		}
		if (BotRunner::isWindows()) {
			$this->logger->warning('Console not available on Windows');
			return;
		}
		$this->useReadline = function_exists('readline_callback_handler_install');
		if (!$this->useReadline) {
			$this->logger->warning('readline not supported on this platform, using basic console');
			$callback = [$this, "processStdin"];
		} else {
			$callback = function(): void {
				readline_callback_read_char();
			};
		}
		$this->loadHistory();
		$this->socket = STDIN;
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			$callback,
		);
		$this->timer->callLater(1, function(): void {
			$this->logger->notice("StdIn console activated, accepting commands");
			$this->socketManager->addSocketNotifier($this->notifier);
			if ($this->useReadline) {
				\Safe\readline_callback_handler_install('> ', [$this, 'processLine']);
			} else {
				echo("> ");
			}
		});
	}

	/**
	 * Handle data arriving on stdin
	 */
	public function processStdin(): void {
		if (!is_resource($this->socket)) {
			return;
		}
		if (feof($this->socket)) {
			echo("EOF received, closing console.\n");
			@fclose($this->socket);
			$this->socketManager->removeSocketNotifier($this->notifier);
			return;
		}
		$line = fgets($this->socket);
		if ($line !== false) {
			$this->processLine(trim($line));
			echo("> ");
		}
	}

	public function processLine(?string $line): void {
		if ($line === null || trim($line) === '') {
			return;
		}
		if (substr($line, 0, 1) === $this->settingManager->getString('symbol')) {
			$line = substr($line, 1);
			if (trim($line) === '') {
				return;
			}
		}
		if ($this->useReadline) {
			\Safe\readline_add_history($line);
			$this->saveHistory();
			\Safe\readline_callback_handler_install('> ', [$this, 'processLine']);
		}
		$context = new CmdContext($this->config->superAdmin);
		$context->channel = "msg";
		$context->message = $line;
		$context->sendto = new ConsoleCommandReply($this->chatBot);
		Registry::injectDependencies($context->sendto);
		$this->chatBot->getUid($context->char->name, function (?int $uid, CmdContext $context): void {
			$context->char->id = $uid;
			$this->commandManager->processCmd($context);
		}, $context);
	}
}
