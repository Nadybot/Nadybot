<?php declare(strict_types=1);

namespace Nadybot\Core;

use Amp\Loop;
use Exception;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Socket\AsyncSocket;
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketController;

class WebsocketServer extends WebsocketBase {
	#[NCA\Inject]
	public SocketManager $socketManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public WebsocketController $websocketController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public string $uuid;


	/** @var string[] */
	protected array $subscriptions = [];

	public function __construct(AsyncSocket $socket) {
		$this->maskData = false;
		$this->socket = $socket->getSocket();
		$this->connected = true;
		$this->lastReadTime = time();
		$this->sendQueue = $socket->getWriteQueue();
		if (!is_resource($this->socket)) {
			throw new Exception("Tried to create a websocket server with a closed socket.");
		}
		$this->peerName = \Safe\stream_socket_get_name($this->socket, true);
		[$usecs, $secs] = explode(" ", microtime(false));
		$this->uuid = bin2hex(\Safe\pack("NN", (int)$secs, (int)((float)$usecs*1000000)) . random_bytes(16));
		\Safe\stream_set_blocking($this->socket, false);
	}

	public function serve(): void {
		$this->listenForWebsocketReadWrite();
		$this->websocketController->registerClient($this);
	}

	public function getUUID(): string {
		return $this->uuid;
	}

	public function subscribe(string ...$events): void {
		$this->subscriptions = $events;
	}

	/** @return string[] */
	public function getSubscriptions(): array {
		return $this->subscriptions;
	}

	protected function write(string $data): bool {
		$result = parent::write($data);
		if ($result === false) {
			$this->resetClient();
		}
		return $result;
	}

	protected function resetClient(): void {
		if (isset($this->timeoutHandle)) {
			Loop::cancel($this->timeoutHandle);
			$this->timeoutHandle = null;
		}
		if ($this->notifier) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		$this->websocketController->unregisterClient($this);
		// $this->server->removeClient($this);
	}
}
