<?php declare(strict_types=1);

namespace Nadybot\Core;

class WebsocketClient extends WebsocketBase {
	protected string $uri;
	/** @var array<string,string> */
	protected array $headers = [];
	protected bool $isSSL = false;

	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	public function __destruct() {
		if ($this->isConnected() && is_resource($this->socket)) {
			@fclose($this->socket);
		}
		$this->socket = null;
	}

	protected function resetClient(): void {
		$this->socket = null;
		$this->isClosing = true;
		$this->lastOpcode = null;
		$this->closeStatus = null;
		$this->sendQueue = [];
		$this->receiveBuffer = "";
		$this->lastReadTime = null;
		$this->connected = false;
		if (isset($this->timeoutChecker)) {
			$this->timer->abortEvent($this->timeoutChecker);
		}
		if ($this->notifier) {
			$this->socketManager->removeSocketNotifier($this->notifier);
			$this->notifier = null;
		}
	}

	public function withURI(string $uri): self {
		$this->uri = $uri;
		return $this;
	}

	public function withTimeout(int $timeout): self {
		$this->timeout = $timeout;
		return $this;
	}

	public function withframeSize(int $frameSize): self {
		$this->frameSize = $frameSize;
		return $this;
	}

	/**
	 * Set a headers to be send with the request
	 */
	public function withHeader(string $header, string $value): self {
		$this->headers[$header] = $value;
		return $this;
	}

	public function throwError(int $code, string $message): void {
		$event = $this->getEvent("error");
		$event->code = $code;
		$event->data = $message;
		if ($this->isConnected() && is_resource($this->socket)) {
			@fclose($this->socket);
			$this->resetClient();
		}
		$this->fireEvent(static::ON_ERROR, $event);
	}

	public function connect(): bool {
		$urlParts = parse_url($this->uri);
		if ($urlParts === false
			|| empty($urlParts)
			|| empty($urlParts['scheme'])
			|| empty($urlParts['host'])
		) {
			$this->throwError(
				WebsocketError::INVALID_URL,
				$this->uri . " is not a fully qualified url"
			);
			return false;
		}
		if (!in_array($urlParts['scheme'], ['ws', 'wss'])) {
			$this->throwError(
				WebsocketError::INVALID_SCHEME,
				$this->uri . " is not a ws:// or wss:// uri"
			);
			return false;
		}
		$port = $urlParts['port'] ?? ($urlParts["scheme"] === 'wss' ? 443 : 80);

		$this->isSSL = $urlParts["scheme"] === 'wss';
		// SSL sockets can't connect async with PHP
		$streamUri = 'tcp://' . $urlParts["host"];
		$this->peerName = $urlParts["host"] . ":" . $port;

		$context = stream_context_create();

		$errno = null;
		$errstr = null;
		$this->timeoutChecker = $this->timer->callLater($this->timeout, [$this, "checkTimeout"]);
		$socket = @stream_socket_client(
			"$streamUri:$port",
			$errno,
			$errstr,
			0,
			STREAM_CLIENT_CONNECT|STREAM_CLIENT_ASYNC_CONNECT,
			$context
		);
		if ($socket === false) {
			$this->throwError(
				WebsocketError::CONNECT_ERROR,
				$errstr
			);
			return false;
		}
		$this->socket = $socket;
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_WRITE,
			[$this, $this->isSSL ? 'enableTLS' : 'upgradeToWebsocket']
		);
		$this->socketManager->addSocketNotifier($this->notifier);
		return true;
	}

	/**
	 * Enable TLS on our socket
	 * This is needed, because you cannot connect asynchronously
	 * when using ssl:// stream sockets. Rather connect with tcp://
	 * and then enable tls once actually connected.
	 *
	 * @return void
	 */
	public function enableTLS(): void {
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		if (!isset($this->socket) || !is_resource($this->socket)) {
			return;
		}
		$result = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		if ($result === false) {
			$errMsg = preg_replace("/^[^:]+ /", "", error_get_last()["message"]??"Unknown SSL error");
			$this->throwError(WebsocketError::CANNOT_ENABLE_SSL, $errMsg);
			return;
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_WRITE,
			[$this, 'upgradeToWebsocket']
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/**
	 * Upgrades a http(s) connection to a websocket
	 */
	public function upgradeToWebsocket(): void {
		$this->connected = true;
		$this->logger->info("[Websocket {uri}] Connected", [
			"uri" => $this->uri,
		]);
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		$urlParts = parse_url($this->uri);
		$port = $urlParts['port'] ?? (($urlParts["scheme"]??null) === 'wss' ? 443 : 80);
		$path = ($urlParts["path"] ?? "/").
			(isset($urlParts["query"]) ? "?" . $urlParts["query"] : "").
			(isset($urlParts["fragment"]) ? "#" . $urlParts["fragment"] : "");

		$key = base64_encode(
			$this->util->genRandomString(
				16,
				'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789'
			)
		);

		$headers = [
			'Host'                  => ($urlParts["host"]??"127.0.0.1") . ":" . $port,
			'User-Agent'            => 'Nadybot ' . BotRunner::getVersion(),
			'Connection'            => 'Upgrade',
			'Upgrade'               => 'websocket',
			'Sec-WebSocket-Key'     => $key,
			'Sec-WebSocket-Version' => '13',
		];

		if (isset($urlParts["user"]) && isset($urlParts["pass"])) {
			$headers['authorization'] = 'Basic '.
				base64_encode($urlParts["user"] . ':' . $urlParts["pass"]) . "\r\n";
		}

		$headers = array_merge($headers, $this->headers);
		$headerStrings =  array_map(
			function (string $key, string $value) {
				return "$key: $value";
			},
			array_keys($headers),
			$headers
		);

		$header = "GET $path HTTP/1.1\r\n" . implode("\r\n", $headerStrings) . "\r\n\r\n";

		$this->write($header);
		$this->logger->debug("[Websocket {uri}] Headers sent", [
			"uri" => $this->uri,
			"header" => $header,
		]);
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			function() use ($key) {
				$this->validateWebsocketUpgradeReply($key);
			}
		);
		$this->lastReadTime = time();
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	public function validateWebsocketUpgradeReply($key): bool {
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		if (!is_resource($this->socket)) {
			return false;
		}
		// Server response headers must be terminated with double CR+LF
		$response = stream_get_line($this->socket, 4096, "\r\n\r\n");
		if ($response === false) {
			$this->throwError(
				WebsocketError::UNKNOWN_ERROR,
				"Unknown error reading websocket upgrade reply"
			);
			return false;
		}
		$this->logger->debug("[Websocket {uri}] Received reply", [
			"uri" => $this->uri,
			"reply" => $response,
		]);

		$urlParts = parse_url($this->uri);
		$path = ($urlParts["path"] ?? "/").
			(isset($urlParts["query"]) ? "?" . $urlParts["query"] : "").
			(isset($urlParts["fragment"]) ? "#" . $urlParts["fragment"] : "");
		if (!preg_match('/Sec-WebSocket-Accept:\s*(.+)$/mUi', $response, $matches)) {
			$address = ($urlParts["scheme"]??"ws") . '://' . ($urlParts["host"]??"127.0.0.1") . $path;
			$this->throwError(
				WebsocketError::WEBSOCKETS_NOT_SUPPORTED,
				"Server at {$address} does not seem to support websockets: {$response}"
			);
			return false;
		}

		$keyAccept = trim($matches[1]);
		$expectedResonse = base64_encode(pack('H*', sha1($key . static::GUID)));

		if ($keyAccept !== $expectedResonse) {
			$this->throwError(
				WebsocketError::INVALID_UPGRADE_RESPONSE,
				'Server sent bad upgrade response.'
			);
			return false;
		}
		$this->logger->info("[Websocket {uri}] connection upgraded to websocket", [
			"uri" => $this->uri,
		]);
		unset($this->notifier);
		$this->listenForRead();
		$event = $this->getEvent("connect");
		$this->fireEvent(static::ON_CONNECT, $event);
		return true;
	}
}
