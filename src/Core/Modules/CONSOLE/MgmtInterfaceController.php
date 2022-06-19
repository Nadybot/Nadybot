<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONSOLE;

use function Amp\{
	File\filesystem,
	Promise\rethrow,
	asyncCall,
	call,
	delay,
};
use function Safe\preg_match;

use Amp\{
	ByteStream\LineReader,
	File\FilesystemException,
	Loop,
	Promise,
	Socket\ResourceSocket,
	Socket\Server,
};
use Closure;
use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	ConfigFile,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	Registry,
	Routing\Source,
	UserException,
};

#[NCA\Instance]
class MgmtInterfaceController extends ModuleInstance {
	public const TYPE_NONE = "None";

	/**
	 * The type and path of the management interface.
	 * Accepts unix://&lt;filename&gt; and tcp://&lt;ip&gt;:&lt;port&gt;
	 */
	#[NCA\Setting\Text]
	public string $mgmtInterface = self::TYPE_NONE;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	private ConfigFile $config;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private CommandManager $commandManager;

	private ?Server $server = null;
	private ?string $socketPath = null;

	public function __destruct() {
		if (isset($this->socketPath)) {
			@unlink($this->socketPath);
		}
	}

	#[NCA\Event(name: "connect", description: "Start the interface")]
	public function onConnect(): void {
		if ($this->mgmtInterface === self::TYPE_NONE) {
			return;
		}
		$this->start();
	}

	#[NCA\SettingChangeHandler("mgmt_interface")]
	public function interfaceChanged(string $setting, string $old, string $new): void {
		if ($new === self::TYPE_NONE) {
			$this->stop();
			return;
		}
		if (!preg_match("#^(unix|tcp)://.#", $new)) {
			throw new UserException(
				"<highlight>{$new}<end> is neither a UNIX domain socket ".
				"(format: unix://&lt;file&gt;), nor a tcp-socket ".
				"(format: tcp://&lt;ip address to listen to&gt;:&lt;port&gt;)."
			);
		}
		$scheme = strstr($new, '://', true);
		if (!in_array($scheme, stream_get_transports())) {
			throw new UserException(
				"Your operating system or PHP installation does not support ".
				"{$scheme}-sockets."
			);
		}
		$this->stop();
		Loop::defer(fn () => $this->start());
	}

	public function start(): void {
		asyncCall(function (): Generator {
			if (isset($this->server) && $this->mgmtInterface === self::TYPE_NONE) {
				return;
			}
			[$scheme, $path] = explode("://", $this->mgmtInterface, 2);
			if ($scheme === "unix") {
				yield from $this->handleExistingUnixSocket($path);
			}
			$this->server = $server = Server::listen($this->mgmtInterface);
			if ($scheme === "unix") {
				$this->socketPath = $path;
				Loop::onSignal(SIGTERM, Closure::fromCallable([$this, "onShutdown"]));
			}
			$this->logger->notice("Management Interface listening on {addr}", [
				"addr" => $this->mgmtInterface,
			]);
			register_shutdown_function(Closure::fromCallable([$this, "onShutdown"]));
			while ($socket = yield $server->accept()) {
				rethrow($this->acceptConnection($socket));
			}
			if ($scheme === "unix") {
				try {
					yield filesystem()->deleteFile($path);
				} catch (FilesystemException) {
				}
				if ($this->socketPath === $path) {
					$this->socketPath = null;
				}
			}
			$this->logger->notice("Management Interface on {addr} shutdown", [
				"addr" => "{$scheme}://{$path}",
			]);
		});
	}

	public function stop(): void {
		if (!isset($this->server)) {
			return;
		}
		$this->server->close();
		$this->server = null;
	}

	private function handleExistingUnixSocket(string $path): Generator {
		if (yield filesystem()->exists($path)) {
			$this->logger->error(
				"Cannot start management interface on {addr}, because ".
				"another process is using it. Will retry until available",
				[
					"addr" => $this->mgmtInterface,
				]
			);
		}
		while (@file_exists($path)) {
			yield delay(1000);
			clearstatcache();
		}
	}

	private function onShutdown(): void {
		if (isset($this->socketPath)) {
			@unlink($this->socketPath);
		}
	}

	/** @return Promise<void> */
	private function acceptConnection(ResourceSocket $socket): Promise {
		return call(function () use ($socket): Generator {
			$reader = new LineReader($socket);
			$this->logger->notice("New connection on management interface from " . $socket->getRemoteAddress());
			while (null !== $line = yield $reader->readLine()) {
				yield $this->processLine($socket, $line);
			}
			yield $socket->end();
			$this->logger->notice("Connection closed");
		});
	}

	/** @return Promise<void> */
	private function processLine(ResourceSocket $socket, string $line): Promise {
		return call(function () use ($line, $socket): Generator {
			if (trim($line) === "") {
				return;
			}
			$context = new CmdContext($this->config->superAdmins[0]??"<no superadmin set>");
			$context->message = $line;
			$context->source = Source::CONSOLE;
			$context->sendto = new SocketCommandReply($socket);
			Registry::injectDependencies($context->sendto);
			$uid = yield $this->chatBot->getUid2($context->char->name);
			$context->char->id = $uid;
			$context->setIsDM(true);
			$this->commandManager->checkAndHandleCmd($context);
		});
	}
}
