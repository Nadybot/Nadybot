<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONSOLE;

use function Amp\{
	ByteStream\splitLines,
	async,
	delay,
};
use function Safe\preg_match;

use Amp\{
	File\FilesystemException,
	Socket,
	Socket\ResourceSocket,
	Socket\ServerSocket,
};
use Closure;
use Exception;
use Nadybot\Core\Event\ConnectEvent;
use Nadybot\Core\Filesystem;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	Config\BotConfig,
	ModuleInstance,
	Nadybot,
	Registry,
	Routing\Source,
	UserException,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

#[NCA\Instance]
class MgmtInterfaceController extends ModuleInstance {
	public const TYPE_NONE = 'None';

	/**
	 * The type and path of the management interface.
	 * Accepts unix://&lt;filename&gt; and tcp://&lt;ip&gt;:&lt;port&gt;
	 */
	#[NCA\Setting\Text]
	public string $mgmtInterface = self::TYPE_NONE;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private CommandManager $commandManager;

	private ?ServerSocket $server = null;
	private ?string $socketPath = null;

	public function __destruct() {
		if (isset($this->socketPath)) {
			$this->fs->deleteFile($this->socketPath);
		}
	}

	#[NCA\Event(name: ConnectEvent::EVENT_MASK, description: 'Start the interface')]
	public function onConnect(): void {
		if ($this->mgmtInterface === self::TYPE_NONE) {
			return;
		}
		$this->start();
	}

	#[NCA\SettingChangeHandler('mgmt_interface')]
	public function interfaceChanged(string $setting, string $old, string $new): void {
		if ($new === self::TYPE_NONE) {
			$this->stop();
			return;
		}
		if (!preg_match('#^(unix|tcp)://.#', $new)) {
			throw new UserException(
				"<highlight>{$new}<end> is neither a UNIX domain socket ".
				'(format: unix://&lt;file&gt;), nor a tcp-socket '.
				'(format: tcp://&lt;ip address to listen to&gt;:&lt;port&gt;).'
			);
		}
		$scheme = strstr($new, '://', true);
		if (!in_array($scheme, stream_get_transports())) {
			throw new UserException(
				'Your operating system or PHP installation does not support '.
				"{$scheme}-sockets."
			);
		}
		$this->stop();
		async($this->start(...));
	}

	public function start(): void {
		async($this->internalStart(...));
	}

	public function stop(): void {
		if (!isset($this->server)) {
			return;
		}
		$this->server->close();
		$this->server = null;
	}

	private function internalStart(): void {
		if (isset($this->server) && $this->mgmtInterface === self::TYPE_NONE) {
			return;
		}
		$parts = explode('://', $this->mgmtInterface, 2);
		if (count($parts) !== 2) {
			throw new Exception("Invalid URL {$this->mgmtInterface} found");
		}
		[$scheme, $path] = $parts;
		if ($scheme === 'unix') {
			$this->handleExistingUnixSocket($path);
		}
		$this->server = $server = Socket\listen($this->mgmtInterface);
		if ($scheme === 'unix') {
			$this->socketPath = $path;
			EventLoop::onSignal(\SIGTERM, function (string $token, int $signal): void {
				$this->onShutdown();
			});
		}
		$this->logger->notice('Management Interface listening on {addr}', [
			'addr' => $this->mgmtInterface,
		]);
		register_shutdown_function(Closure::fromCallable([$this, 'onShutdown']));
		while ($socket = $server->accept()) {
			async($this->handleConnection(...), $socket);
		}
		if ($scheme === 'unix') {
			try {
				$this->fs->deleteFile($path);
			} catch (FilesystemException) {
			}
			if ($this->socketPath === $path) {
				$this->socketPath = null;
			}
		}
		$this->logger->notice('Management Interface on {addr} shutdown', [
			'addr' => "{$scheme}://{$path}",
		]);
	}

	private function handleExistingUnixSocket(string $path): void {
		if ($this->fs->exists($path)) {
			$this->logger->error(
				'Cannot start management interface on {addr}, because '.
				'another process is using it. Will retry until available',
				[
					'addr' => $this->mgmtInterface,
				]
			);
		}
		while ($this->fs->exists($path)) {
			delay(1);
			clearstatcache();
		}
	}

	private function onShutdown(): void {
		if (isset($this->socketPath)) {
			$this->fs->deleteFile($this->socketPath);
		}
	}

	private function handleConnection(ResourceSocket $socket): void {
		$reader = splitLines($socket);
		$this->logger->notice('New connection on management interface from ' . $socket->getRemoteAddress());
		foreach ($reader as $line) {
			$this->processLine($socket, $line);
		}
		$socket->end();
		$this->logger->notice('Connection closed');
	}

	private function processLine(ResourceSocket $socket, string $line): void {
		if (trim($line) === '') {
			return;
		}
		$context = new CmdContext($this->config->general->superAdmins[0]??'<no superadmin set>');
		$context->message = $line;
		$context->source = Source::CONSOLE;
		$context->sendto = new SocketCommandReply($socket);
		Registry::injectDependencies($context->sendto);
		$uid = $this->chatBot->getUid($context->char->name);
		$context->char->id = $uid;
		$context->setIsDM(true);
		$this->commandManager->checkAndHandleCmd($context);
	}
}
