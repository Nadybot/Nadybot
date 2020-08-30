<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use InvalidArgumentException;

class WebsocketClient {
	public const GUID            = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
	public const OP_CONTINUATION = 0;
	public const OP_TEXT         = 1;
	public const OP_BINARY       = 2;
	public const OP_CLOSE        = 8;
	public const OP_PING         = 9;
	public const OP_PONG         = 10;

	public const ON_CONNECT = "connect";
	public const ON_TEXT = "text";
	public const ON_BINARY = "binary";
	public const ON_CLOSE = "close";
	public const ON_PING = "ping";
	public const ON_ERROR = "error";

	public const ALLOWED_EVENTS = [
		self::ON_CONNECT,
		self::ON_TEXT,
		self::ON_BINARY,
		self::ON_CLOSE,
		self::ON_PING,
		self::ON_ERROR,
	];

	protected const ALLOWED_OPCODES = [
		'continuation' => self::OP_CONTINUATION,
		'text'         => self::OP_TEXT,
		'binary'       => self::OP_BINARY,
		'close'        => self::OP_CLOSE,
		'ping'         => self::OP_PING,
		'pong'         => self::OP_PONG,
	];

	/** @var array<string,callable> */
	protected array $eventCallbacks = [];

	protected string $uri;
	protected $socket;
	protected bool $isClosing = false;
	protected ?string $lastOpcode = null;
	protected ?int $closeStatus = null;
	protected int $timeout = 5;
	protected int $frameSize = 4096;
	/** @var string[] */
	protected array $sendQueue = [];
	protected string $receiveBuffer = "";
	/** @var array<string,string> */
	protected array $headers = [];
	public ?SocketNotifier $notifier = null;
	protected bool $isSSL = false;
	protected $connected = false;
	protected ?TimerEvent $timeoutChecker = null;

	protected ?int $lastReadTime = null;

	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	public function __destruct() {
		if ($this->isConnected()) {
			@fclose($this->socket);
		}
		$this->socket = null;
	}

	public function on(string $event, callable $callback): self {
		if (!in_array($event, static::ALLOWED_EVENTS)) {
			throw new InvalidArgumentException("$event is not an allowed event.");
		}
		$this->eventCallbacks[$event] = $callback;
		return $this;
	}

	protected function fireEvent($eventName, WebsocketEvent $event): void {
		if (isset($this->eventCallbacks[$eventName])) {
			$this->eventCallbacks[$eventName]($event);
		}
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

	public function isConnected() {
		return isset($this->socket)
			&& is_resource($this->socket)
			&& get_resource_type($this->socket) === 'persistent stream';
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
		if ($this->isConnected()) {
			@fclose($this->socket);
			$this->resetClient();
		}
		$this->fireEvent(static::ON_ERROR, $event);
	}

	public function checkTimeout() {
		if (!$this->isConnected() || !$this->connected) {
			$this->throwError(
				WebsocketErrorEvent::CONNECT_TIMEOUT,
				"Connecting to {$this->uri} timed out."
			);
			return;
		}
		if (time() - $this->lastReadTime >= 30) {
			$this->send("", 'ping', true);
		}
		if (time() - $this->lastReadTime >= 30 + $this->timeout) {
			$this->throwError(
				WebsocketErrorEvent::CONNECT_TIMEOUT,
				"Connection to {$this->uri} timed out, no response to ping."
			);
		} else {
			$this->timeoutChecker = $this->timer->callLater(5, [$this, "checkTimeout"]);
		}
	}

	public function connect(): bool {
		$urlParts = parse_url($this->uri);
		if ($urlParts === false
			|| empty($urlParts)
			|| empty($urlParts['scheme'])
			|| empty($urlParts['host'])
		) {
			$this->throwError(
				WebsocketErrorEvent::INVALID_URL,
				$this->uri . " is not a fully qualified url"
			);
			return false;
		}
		if (!in_array($urlParts['scheme'], ['ws', 'wss'])) {
			$this->throwError(
				WebsocketErrorEvent::INVALID_SCHEME,
				$this->uri . " is not a ws:// or wss:// uri"
			);
			return false;
		}
		$port = $urlParts['port'] ?? ($urlParts["scheme"] === 'wss' ? 443 : 80);

		$this->isSSL = $urlParts["scheme"] === 'wss';
		// SSL sockets can't connect async with PHP
		$streamUri = 'tcp://' . $urlParts["host"];

		$context = stream_context_create();

		$errno = null;
		$errstr = null;
		$this->timeoutChecker = $this->timer->callLater($this->timeout, [$this, "checkTimeout"]);
		$this->socket = @stream_socket_client(
			"$streamUri:$port",
			$errno,
			$errstr,
			0,
			STREAM_CLIENT_CONNECT|STREAM_CLIENT_ASYNC_CONNECT|STREAM_CLIENT_PERSISTENT,
			$context
		);
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
		$this->socketManager->removeSocketNotifier($this->notifier);
		stream_socket_enable_crypto($this->socket, $enable=true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
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
		$this->logger->log("DEBUG", "Connected to {$this->uri}");
		$this->socketManager->removeSocketNotifier($this->notifier);
		$urlParts = parse_url($this->uri);
		$port = $urlParts['port'] ?? ($urlParts["scheme"] === 'wss' ? 443 : 80);
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
			'Host'                  => $urlParts["host"] . ":" . $port,
			'User-Agent'            => 'Nadybot',
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
		$this->logger->log("DEBUG", "Headers sent");
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			function() use ($key) {
				$this->validateWebsocketUpgradeReply($key);
			}
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	public function validateWebsocketUpgradeReply($key) {
		$this->socketManager->removeSocketNotifier($this->notifier);
		// Server response headers must be terminated with double CR+LF
		$response = stream_get_line($this->socket, 4096, "\r\n\r\n");

		$urlParts = parse_url($this->uri);
		$path = ($urlParts["path"] ?? "/").
			(isset($urlParts["query"]) ? "?" . $urlParts["query"] : "").
			(isset($urlParts["fragment"]) ? "#" . $urlParts["fragment"] : "");
		if (!preg_match('/Sec-WebSocket-Accept:\s*(.+)$/mUi', $response, $matches)) {
			$address = $urlParts["scheme"] . '://' . $urlParts["host"] . $path;
			$this->throwError(
				WebsocketErrorEvent::WEBSOCKETS_NOT_SUPPORTED,
				"Server at {$address} does not seem to support websockets: {$response}"
			);
			return false;
		}

		$keyAccept = trim($matches[1]);
		$expectedResonse = base64_encode(pack('H*', sha1($key . static::GUID)));

		if ($keyAccept !== $expectedResonse) {
			$this->throwError(
				WebsocketErrorEvent::INVALID_UPGRADE_RESPONSE,
				'Server sent bad upgrade response.'
			);
			return false;
		}
		$this->logger->log("DEBUG", "connection upgraded to websocket on {$this->uri}");
		$this->listenForRead();
		$event = $this->getEvent("connect");
		$this->fireEvent(static::ON_CONNECT, $event);
		return true;
	}

	protected function getEvent($eventName=null): WebsocketEvent {
		$eventName ??= $this->lastOpcode;
		$event = new WebsocketEvent();
		$event->eventName = $eventName;
		$event->websocket = $this;
		return $event;
	}

	/**
	 * Handler method which will be called when activity occurs in the SocketNotifier.
	 *
	 * @internal
	 */
	public function onStreamActivity(int $type): void {
		if ($this->isConnected() === false) {
			return;
		}

		if ($type === SocketNotifier::ACTIVITY_READ) {
			$this->processResponse();
			return;
		}
		if ($type === SocketNotifier::ACTIVITY_WRITE) {
			$this->processQueue();
			return;
		}

		throw new Exception("Illegal notification ($type) received.");
	}

	public function send(string $data, string $opcode='text', bool $masked=true): void {
		if (!$this->isConnected()) {
			$this->connect();
		}
		$this->logger->log("DEBUG", "[{$this->uri}] Queueing packet");

		if (!isset(static::ALLOWED_OPCODES[$opcode])) {
			throw new Exception("Bad opcode '$opcode'.");
		}

		$dataChunks = str_split($data, $this->frameSize);

		while (count($dataChunks)) {
			$chunk = array_shift($dataChunks);
			$final = empty($dataChunks);

			$frame = $this->toFrame($final, $chunk, $opcode, $masked);
			$this->lastEnqueueTime = time();
			$this->sendQueue []= $frame;

			$opcode = 'continuation';
			$this->logger->log("DEBUG", "[{$this->uri}] Queueing frame of packet");
		}
		$this->listenForReadWrite();
	}

	protected function listenForRead(): void {
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			[$this, 'onStreamActivity']
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	protected function listenForReadWrite() {
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ | SocketNotifier::ACTIVITY_WRITE,
			[$this, 'onStreamActivity']
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	public function processQueue(): void {
		if (count($this->sendQueue) === 0) {
			$this->logger->log("DEBUG", "[{$this->uri}] Queue empty");
			$this->listenForRead();
			return;
		}
		$packet = array_shift($this->sendQueue);
		$this->write($packet);
	}

	protected function write(string $data): bool {
		$this->logger->log("DEBUG", "[{$this->uri}] Writing " . strlen($data) . " bytes of data");
		while (strlen($data) > 0) {
			$written = fwrite($this->socket, $data);
			if ($written === false) {
				$length = strlen($data);
				@fclose($this->socket);
				$this->throwError(
					WebsocketErrorEvent::WRITE_ERROR,
					"Failed to write $length bytes to socket."
				);
				return false;
			}
			$data = substr($data, $written);
			$this->lastWriteTime = time();
		}
		return true;
	}

	protected function toFrame(bool $final, string $data, string $opcode, bool $masked): string {
		$frame= pack("C", ((int)$final << 7)
			+ (static::ALLOWED_OPCODES[$opcode]))[0];
		$maskedBit = 128 * (int)$masked;
		$dataLength = strlen($data);
		if ($dataLength > 65535) {
			$frame.= pack("CJ", 127 + $maskedBit, $dataLength);
		} elseif ($dataLength > 125) {
			$frame.= pack("Cn", 126 + $maskedBit, $dataLength);
		} else {
			$frame.= pack("C", $dataLength + $maskedBit);
		}

		if ($masked) {
			$mask = '';
			for ($i = 0; $i < 4; $i++) {
				$mask .= chr(rand(0, 255));
			}
			$frame .= $mask;
		}

		for ($i = 0; $i < $dataLength; $i++) {
			$frame .= ($masked === true) ? $data[$i] ^ $mask[$i % 4] : $data[$i];
		}

		return $frame;
	}

	public function processResponse(): void {
		try {
			$response = $this->receiveFragment();
		} catch (Exception $e) {
			return;
		}
		$this->receiveBuffer .= $response[0];
		// Not a complete package yet
		if (!$response[1]) {
			$this->logger->log("DEBUG", "[{$this->uri}] fragment received");
			return;
		}
		$this->logger->log(
			"DEBUG",
			"[{$this->uri}] last fragment received, package completed"
		);
		$event = $this->getEvent();
		$event->data = $this->receiveBuffer;
		$this->receiveBuffer = "";
		if ($this->lastOpcode !== "close") {
			$this->fireEvent($this->lastOpcode, $event);
		}
	}

	protected function receiveFragment(): array {
		$data = $this->read(2);

		// Is this the final fragment?  // Bit 0 in byte 0
		$final = (ord($data[0]) & 1 << 7) !== 0;

		$opcodeValue = ord($data[0]) & 31; // Bits 4-7
		$opcodeValueToName = array_flip(static::ALLOWED_OPCODES);
		if (!array_key_exists($opcodeValue, $opcodeValueToName)) {
			$this->throwError(
				WebsocketErrorEvent::BAD_OPCODE,
				"Bad opcode in websocket frame: $opcodeValue"
			);
			return [null, true];
		}
		$opcode = $opcodeValueToName[$opcodeValue];
		$this->logger->log("DEBUG", "[{$this->uri}] $opcode received");

		if ($opcodeValue !== static::OP_CONTINUATION) {
			$this->lastOpcode = $opcode;
		}

		$mask = (ord($data[1]) >> 7) !== 0;

		$payload = '';

		$payloadLength = ord($data[1]) & 127; // Bits 1-7 in byte 1
		if ($payloadLength > 125) {
			if ($payloadLength === 126) {
				$data = $this->read(2); // 126: Payload is a 16-bit unsigned int
				$payloadLength = unpack("n", $data)[1];
			} else {
				$data = $this->read(8); // 127: Payload is a 64-bit unsigned int
				$payloadLength = unpack("J", $data)[1];
			}
		}

		if ($mask) {
			$maskingKey = $this->read(4);
		}

		// Get the actual payload, if any (might not be for e.g. close frames)
		if ($payloadLength > 0) {
			$data = $this->read($payloadLength);

			if ($mask) {
				for ($i = 0; $i < $payloadLength; $i++) {
					$payload .= ($data[$i] ^ $maskingKey[$i % 4]);
				}
			} else {
				$payload = $data;
			}
		}

		if ($opcode === 'ping') {
			$this->send($payload, 'pong', true);
			return [$payload, $final];
		}

		if ($opcode !== 'close') {
			return [$payload, $final];
		}
		// Get the close status.
		if ($payloadLength > 0) {
			$statusBin = $payload[0] . $payload[1];
			$status = bindec(sprintf("%08b%08b", ord($payload[0]), ord($payload[1])));
			$this->closeStatus = $status;
		}
		// Get the additional close-message
		if ($payloadLength >= 2) {
			$payload = substr($payload, 2);
		}

		if ($this->isClosing) {
			$this->isClosing = false;
		} else {
			$this->send($statusBin . 'Close acknowledged: ' . $status, 'close', true);
		}

		// Close the socket.
		stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);

		// Closing should not return message.
		return [null, true];
	}

	protected function read(int $length): string {
		$data = '';
		while (strlen($data) < $length) {
			$buffer = fread($this->socket, $length - strlen($data));
			$meta = stream_get_meta_data($this->socket);
			if ($buffer === false) {
				$read = strlen($data);
				$this->resetClient();
				throw new Exception("Broken frame, read $read bytes out of $length.");
			}
			if ($meta["timed_out"] === true || $buffer === '') {
				if (feof($this->socket)) {
					@fclose($this->socket);
					$this->logger->log("INFO", "Socket closed with status " . $this->closeStatus ?? "<unknown>");
					$this->socket = null;
					$event = $this->getEvent("close");
					$event->code = $this->closeStatus;
					$this->resetClient();
					$this->fireEvent(static::ON_CLOSE, $event);
					throw new Exception("Socket closed.");
				}
				throw new Exception("Illegal data received, cannot handle.");
			}
			$data .= $buffer;
			$this->lastReadTime = time();
		}
		return $data;
	}

	public function close($status=1000, $message='kthxbye'): void {
		if (!$this->isConnected()) {
			return;
		}
		$statusString = pack("n", $status);
		$this->isClosing = true;
		$this->send($statusString . $message, 'close', true);
		$this->logger->log("DEBUG", "Closing with status: {$status}.");
	}
}
