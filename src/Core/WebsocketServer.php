<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Socket\AsyncSocket;
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketController;

class WebsocketServer extends WebsocketBase {

	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public WebsocketController $websocketController;

	/** @Logger */
	public LoggerWrapper $logger;

	
	/** @var string[] */
	protected array $subscriptions = [];
	
	public string $uuid;

	public function __construct(AsyncSocket $socket) {
		$this->maskData = false;
		$this->socket = $socket->getSocket();
		$this->connected = true;
		$this->lastReadTime = time();
		$this->sendQueue = $socket->getWriteQueue();
		$this->peerName = stream_socket_get_name($this->socket, true);
		[$usecs, $secs] = explode(" ", microtime(false));
		$this->uuid = bin2hex(pack("NN", (int)$secs, (int)($usecs*1000000)) . random_bytes(16));
		stream_set_blocking($this->socket, false);
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
		if (isset($this->timeoutChecker)) {
			$this->timer->abortEvent($this->timeoutChecker);
		}
		if ($this->notifier) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		$this->websocketController->unregisterClient($this);
		// $this->server->removeClient($this);
	}
}
