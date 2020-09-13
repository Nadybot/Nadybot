<?php declare(strict_types=1);

namespace Nadybot\Core;

class WebsocketServerConnection extends WebsocketBase {
	public const AUTHORIZED = "authorized";

	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	protected WebsocketServer $server;
	protected ?string $requestPath = null;
	protected string $uuid;
	/** @var string[] */
	protected array $subscriptions = [];

	public function __construct(WebsocketServer $server, $socket, string $peerName, int $frameSize) {
		$this->server = $server;
		$this->socket = $socket;
		$this->peerName = $peerName;
		$this->frameSize = $frameSize;
		$this->tags[self::AUTHORIZED] = null;
		[$ip, $port] = explode(":", $peerName);
		$this->uuid = bin2hex(pack("Nn", ip2long($ip), $port) . random_bytes(16));
		stream_set_blocking($this->socket, false);
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

	protected function httpError(int $code, string $name, array $headers=[]): void {
		$response = "<html><head><title>{$name}</title></head><body><h1>".
				"{$code} {$name}".
				"</h1></body></html>";
		$extraHeaders = "";
		foreach ($headers as $key => $value) {
			$extraHeaders .= "$key: $value\r\n";
		}
		$this->write(
			"HTTP/1.1 {$code} {$name}\r\n".
			"Server: Nadybot\r\n".
			$extraHeaders.
			"Content-Type: text/html; charset=utf-8\r\n".
			"Content-Length: " . strlen($response) . "\r\n".
			"\r\n".
			$response
		);
		@fclose($this->socket);
		$this->resetClient();
		return;
	}

	public function awaitWebsocketUpgrade(): void {
		do {
			$buffer = fread($this->socket, 8192);
			if ($buffer === false) {
				return;
			}
			$this->receiveBuffer .= $buffer;
			if (strlen($this->receiveBuffer) > 1024 * 1024) {
				$this->httpError(413, "Request too large");
				return;
			}
		} while (!feof($this->socket) && !strlen($buffer) >= 8192);
		if (!preg_match('/\r?\n\r?\n/', $this->receiveBuffer)) {
			return;
		}
		[$header, $body] = preg_split("/\r?\n\r?\n/", $this->receiveBuffer);
		$this->receiveBuffer = "";
		$this->socketManager->removeSocketNotifier($this->notifier);

		if (!preg_match('/^[A-Z]+\s+.+\s+HTTP\//i', $header, $matches)) {
			$this->logger->log('DEBUG', "Invalid request received");
			$this->httpError(400, "Bad Request");
			return;
		}
		if (!preg_match('/^GET (.*?) HTTP\/\d/i', $header, $matches)) {
			$error = "Not a GET request: {$header}";
			$this->logger->log('DEBUG', $error);
			$this->httpError(405, "Method Not Allowed");
			return;
		}
		if (!preg_match('/^Authorization:\s*Bearer\s+(.+)$/mi', $header, $matches)
			|| !$this->server->checkAuthorization($matches[1])) {
			$error = "Client did not send Authorization header";
			if (!empty($matches)) {
				$error = "Invalid or expired authorization token used";
			}
			$this->logger->log('ERROR', $error);
			$this->httpError(
				401,
				"Unauthorized",
				[
					"WWW-Authenticate" => "Bearer realm=\"" . $this->server->chatBot->vars["name"] . "\"",
				]
			);
			return;
		}
		if (!preg_match('/^Upgrade:\s+.*?websocket$/mi', $header, $matches)) {
			$error = "Client did not request Websocket upgrade";
			$this->logger->log('ERROR', $error);
			$this->httpError(
				426,
				"Upgrade Required",
				[
					"Connection" => "Upgrade",
					"Upgrade" => "websocket",
					"Sec-WebSocket-Version" => "13",
					"Sec-WebSocket-Protocol" => "nadybot",
				]
			);
			return;
		}
		$this->requestPath = $matches[1];
		if (!preg_match('/^Sec-WebSocket-Key:\s+(.*)$/mi', $header, $matches)) {
			$error = "Client had no Key in upgrade request: {$header}";
			$this->logger->log('ERROR', $error);
			$this->httpError(404, "Not Found");
			return;
		}
		$key = trim($matches[1]);

		if (preg_match('/^Sec-WebSocket-Prococol:\s+(.*)$/mi', $header, $matches)) {
			if (!in_array("nadybot", preg_split("/\s*,\s*/", $matches[1]))) {
				$this->httpError(
					426,
					"Upgrade Required",
					[
						"Connection" => "Upgrade",
						"Upgrade" => "websocket",
						"Sec-WebSocket-Version" => "13",
						"Sec-WebSocket-Protocol" => "nadybot",
					]
				);
				return;
			}
		}

		$this->logger->log('DEBUG', "WebSocket Request: $header");
		/** @todo Validate key length and base 64 */
		$responseKey = base64_encode(pack('H*', sha1($key . static::GUID)));

		$header = "HTTP/1.1 101 Switching Protocols\r\n".
			"Connection: upgrade\r\n".
			"Upgrade: websocket\r\n".
			"Sec-WebSocket-Accept: $responseKey\r\n".
			"Sec-WebSocket-Protocol: nadybot\r\n".
			"\r\n";

		$this->write($header);
		$this->connected = true;
		$event = $this->getEvent("connect");
		$this->fireEvent(static::ON_CONNECT, $event);
		$this->timer->abortEvent($this->timeoutChecker);
		$this->listenForReadWrite();
	}

	/**
	 * Start handling the connection
	 */
	public function listen(): void {
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_WRITE,
			[$this, "enableTLS"]
		);
		$this->socketManager->addSocketNotifier($this->notifier);
		$this->timeoutChecker = $this->timer->callLater($this->timeout, [$this, "checkTimeout"]);
	}
	
	public function checkTimeout(): void {
		if ($this->connected === true) {
			return;
		}
		$this->logger->log(
			'INFO',
			'Websocket connection from ' . $this->peerName . ' timed out after '.
			$this->timeout . 's before authenticating'
		);
		@fclose($this->socket);
		$this->resetClient();
	}

	public function enableTLS(): void {
		stream_set_timeout($this->socket, $this->timeout);
		$result = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_SSLv23_SERVER);
		if ($result === false) {
			$this->logger->log('ERROR', 'TLS handshake failed with client');
			@fclose($this->socket);
			$this->resetClient();
			return;
		} elseif ($result === true) {
			$this->socketManager->removeSocketNotifier($this->notifier);
			$this->notifier = new SocketNotifier(
				$this->socket,
				SocketNotifier::ACTIVITY_READ,
				[$this, "awaitWebsocketUpgrade"]
			);
			$this->socketManager->addSocketNotifier($this->notifier);
		}
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
		$this->server->removeClient($this);
	}
}
