<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Exception;
use InvalidArgumentException;
use Nadybot\Core\Socket\ShutdownRequest;
use Nadybot\Core\Socket\WriteClosureInterface;

class WebsocketBase {
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

	protected const FRAMESIZE = 4096;

	/** @var array<string,callable> */
	protected array $eventCallbacks = [];

	/** @var array<string,mixed> */
	protected $tags = [];

	/**
	 * @var null|resource
	 * @psalm-var null|resource|closed-resource
	 */
	protected $socket;
	protected string $peerName = "Unknown websocket";
	protected int $timeout = 55;
	protected bool $isClosing = false;
	protected ?string $lastOpcode = null;
	protected ?int $closeStatus = null;
	/** @var array<WriteClosureInterface|ShutdownRequest|string> */
	protected array $sendQueue = [];
	protected string $receiveBuffer = "";
	public ?SocketNotifier $notifier = null;
	protected bool $connected = false;
	protected ?int $lastReadTime = null;
	protected ?int $pendingPingTime = null;
	protected ?TimerEvent $timeoutChecker = null;
	public bool $maskData = true;
	protected string $uri;
	protected ?int $lastWriteTime = null;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public SocketManager $socketManager;

	public function connect(): bool {
		return true;
	}

	public function getPeer(): ?string {
		return $this->peerName;
	}

	/**
	 * @return static
	 */
	public function on(string $event, callable $callback): self {
		if (!in_array($event, static::ALLOWED_EVENTS)) {
			throw new InvalidArgumentException("$event is not an allowed event.");
		}
		$this->eventCallbacks[$event] = $callback;
		return $this;
	}

	protected function fireEvent(string $eventName, WebsocketCallback $event): void {
		if (isset($this->eventCallbacks[$eventName])) {
			$this->eventCallbacks[$eventName]($event);
		}
	}

	protected function getEvent(?string $eventName=null): WebsocketCallback {
		$eventName ??= $this->lastOpcode ?? "unknown";
		$event = new WebsocketCallback();
		$event->eventName = $eventName;
		$event->websocket = $this;
		return $event;
	}

	public function throwError(int $code, string $message): void {
		$event = $this->getEvent("error");
		$event->code = $code;
		$event->data = $message;
		$this->fireEvent(static::ON_ERROR, $event);
	}

	public function isConnected(): bool {
		return isset($this->socket)
			&& (!is_resource($this->socket) || (
				get_resource_type($this->socket) === 'persistent stream'
				|| get_resource_type($this->socket) === 'stream'
			));
	}

	public function checkTimeout(): void {
		$uri = ($this->uri ?? $this->peerName);
		if (!$this->isConnected() || !$this->connected) {
			$this->throwError(
				WebsocketError::CONNECT_TIMEOUT,
				"Connecting to {$uri} timed out."
			);
			return;
		}
		if ($this->pendingPingTime) {
			$this->logger->debug(
				"[Websocket {uri}] No data received for {noPingTime}s, ping pending since {pendingPingTime}s",
				[
					"uri" => $uri,
					"noPingTime" => (time() - ($this->lastReadTime??0)),
					"pendingPingTime" => (time() - $this->pendingPingTime)
				]
			);
		}
		if (!isset($this->lastReadTime) || time() - $this->lastReadTime >= 30) {
			$this->logger->debug(
				"[Websocket {uri}] No data received for {noPingTime}s",
				[
					"uri" => $uri,
					"noPingTime" => (time() - ($this->lastReadTime??0)),
				]
			);
			$this->send("", 'ping');
		}
		if (isset($this->pendingPingTime) && time() - $this->pendingPingTime >= $this->timeout) {
			$this->throwError(
				WebsocketError::CONNECT_TIMEOUT,
				"Connection to {$this->uri} timed out, no response to ping."
			);
		} else {
			$this->timeoutChecker = $this->timer->callLater(5, [$this, "checkTimeout"]);
		}
	}

	protected function toFrame(bool $final, string $data, string $opcode, bool $masked): string {
		$frame= \Safe\pack("C", ((int)$final << 7)
			+ (static::ALLOWED_OPCODES[$opcode]))[0];
		$maskedBit = 128 * (int)$masked;
		$dataLength = strlen($data);
		if ($dataLength > 65535) {
			$frame.= \Safe\pack("CJ", 127 + $maskedBit, $dataLength);
		} elseif ($dataLength > 125) {
			$frame.= \Safe\pack("Cn", 126 + $maskedBit, $dataLength);
		} else {
			$frame.= \Safe\pack("C", $dataLength + $maskedBit);
		}

		if ($masked) {
			$mask = '';
			for ($i = 0; $i < 4; $i++) {
				$mask .= chr(rand(0, 255));
			}
			$frame .= $mask;
			for ($i = 0; $i < $dataLength; $i++) {
				$frame .= $data[$i] ^ $mask[$i % 4];
			}
		} else {
			for ($i = 0; $i < $dataLength; $i++) {
				$frame .= $data[$i];
			}
		}

		return $frame;
	}

	protected function write(string $data): bool {
		$uri = ($this->uri ?? $this->peerName);
		if (strlen($data) === 0 || !is_resource($this->socket)) {
			return true;
		}
		$this->logger->debug(
			"[Websocket {uri}] Writing {numBytes} bytes to websocket",
			[
				"uri" => $uri,
				"raw" => join(" ", str_split(bin2hex($data), 2)),
				"numBytes" => strlen($data),
			]
		);
		$written = fwrite($this->socket, $data);
		if ($written === false) {
			if ((!is_resource($this->socket) || feof($this->socket)) && $this->isClosing) {
				return true;
			}
			$this->logger->error("[Websocket {uri}] Error sending data", [
				"uri" => $this->uri,
			]);
			$length = strlen($data);
			@fclose($this->socket);
			$this->throwError(
				WebsocketError::WRITE_ERROR,
				"Failed to write $length bytes to websocket {$uri}."
			);
			return false;
		}
		$this->logger->debug("[Websocket {uri}] Successfully sent {written} bytes", [
			"uri" => $uri,
			"written" => $written,
		]);

		$data = substr($data, $written);
		if (strlen($data)) {
			array_unshift($this->sendQueue, $data);
		}
		$this->lastWriteTime = time();
		return true;
	}

	public function processQueue(): void {
		$uri = ($this->uri ?? $this->peerName);
		if (count($this->sendQueue) === 0) {
			$this->listenForRead();
			return;
		}
		$packet = array_shift($this->sendQueue);
		/** @psalm-suppress DocblockTypeContradiction */
		if (!is_string($packet)) {
			$this->logger->error("[Websocket {uri}] Illegal item found in send queue", [
				"uri" => $uri,
				"item" => $packet,
			]);
			return;
		}
		$this->write($packet);
	}

	public function processResponse(): void {
		try {
			$response = $this->receiveFragment();
		} catch (Exception $e) {
			return;
		}
		$uri = ($this->uri ?? $this->peerName);
		$this->receiveBuffer .= $response[0]??"";
		// Not a complete package yet
		if (!$response[1]) {
			$this->logger->debug("[Websocket {uri}] fragment received", [
				"fragment" => $response[0],
				"uri" => $uri,
			]);
			return;
		}
		if ($this->receiveBuffer === $response[0]) {
			$this->logger->debug("[Websocket {uri}] Package received", [
				"data" => $this->receiveBuffer,
				"uri" => $uri
			]);
		} else {
			$this->logger->debug("[Websocket {uri}] last fragment received, package complete", [
				"data" => $this->receiveBuffer,
				"uri" => $uri
			]);
		}
		$event = $this->getEvent();
		$event->data = $this->receiveBuffer;
		$this->receiveBuffer = "";
		if (isset($this->lastOpcode) && $this->lastOpcode !== "close") {
			$this->fireEvent($this->lastOpcode, $event);
		}
	}

	/**
	 * @return array<null|int|string>
	 * @psalm-return array{0:null|string, 1:bool}
	 * @phpstan-return array{0:null|string, 1:bool}
	 */
	protected function receiveFragment(): array {
		$data = $this->read(2);

		// Is this the final fragment?  // Bit 0 in byte 0
		$final = (ord($data[0]) & 1 << 7) !== 0;

		if ((ord($data[0]) & 0x70) !== 0) {
			$this->throwError(
				WebsocketError::BAD_RSV,
				"Packet contained non-zero RSV bit(s), but we didn't agree ".
				"on a meaning for this"
			);
			return [null, true];
		}

		$opcodeValue = ord($data[0]) & 31; // Bits 4-7
		$opcodeValueToName = array_flip(static::ALLOWED_OPCODES);
		if (!array_key_exists($opcodeValue, $opcodeValueToName)) {
			$this->throwError(
				WebsocketError::BAD_OPCODE,
				"Bad opcode in websocket frame: $opcodeValue"
			);
			return [null, true];
		}
		$opcode = $opcodeValueToName[$opcodeValue];
		$uri = ($this->uri ?? $this->peerName);

		if ($opcodeValue !== static::OP_CONTINUATION) {
			$this->lastOpcode = $opcode;
		}

		$mask = (ord($data[1]) >> 7) !== 0;

		$payload = '';

		$payloadLength = ord($data[1]) & 127; // Bits 1-7 in byte 1
		if ($payloadLength > 125) {
			if ($payloadLength === 126) {
				$data = $this->read(2); // 126: Payload is a 16-bit unsigned int
				$payloadLength = \Safe\unpack("n", $data)[1];
			} else {
				$data = $this->read(8); // 127: Payload is a 64-bit unsigned int
				if (PHP_INT_SIZE < 8) {
					$payloadLength = \Safe\unpack("N", substr($data, 4))[1];
				} else {
					$payloadLength = \Safe\unpack("J", $data)[1];
				}
			}
		}

		$this->logger->info("[Websocket {uri}] opcode {opcode} ({opcodeName}) received with {payloadLength} bytes of payload", [
			"uri" => $uri,
			"opcodeName" => $opcode,
			"opcode" => $opcodeValue,
			"payloadLength" => $payloadLength,
		]);

		if ($mask) {
			$maskingKey = $this->read(4);
		}

		// Get the actual payload, if any (might not be for e.g. close frames)
		if ($payloadLength > 0) {
			$data = $this->read($payloadLength);

			if (isset($maskingKey)) {
				for ($i = 0; $i < $payloadLength; $i++) {
					$payload .= ($data[$i] ^ $maskingKey[$i % 4]);
				}
			} else {
				$payload = $data;
			}
		}

		if ($opcode === 'ping') {
			$this->send($payload, 'pong');
			return [$payload, $final];
		} elseif ($opcode === 'pong') {
			$this->pendingPingTime = null;
		}

		if ($opcode !== 'close') {
			return [$payload, $final];
		}
		// Get the close status.
		$statusBin = "";
		$status = 0;
		if ($payloadLength > 0) {
			$statusBin = $payload[0] . $payload[1];
			$status = (int)bindec(sprintf("%08b%08b", ord($payload[0]), ord($payload[1])));
			$this->closeStatus = $status;
		}
		// Get the additional close-message
		if ($payloadLength >= 2) {
			$payload = substr($payload, 2);
		}

		if ($this->isClosing) {
			$this->isClosing = false;
		} else {
			$this->isClosing = true;
			$this->send($statusBin . 'Close acknowledged: ' . $status, 'close');
		}

		// Close the socket.
		if (is_resource($this->socket)) {
			$this->logger->debug("[Websocket {uri}] Closing socket", [
				"uri" => $uri
			]);
			\Safe\stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
		}

		// Closing should not return message.
		return [null, true];
	}

	protected function resetClient(): void {
	}

	protected function read(int $length): string {
		$data = '';
		if (!is_resource($this->socket)) {
			return "";
		}
		$uri = ($this->uri ?? $this->peerName);
		while (strlen($data) < $length) {
			// @phpstan-ignore-next-line
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
					$this->logger->info("[Websocket {uri}] Socket closed with status {status}", [
						"uri" => $uri,
						"status" => $this->closeStatus ?? "<unknown>",
					]);
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
			$this->logger->debug("[Websocket {uri}] {numBytes} bytes of websocket data read", [
				"uri" => $uri,
				"numBytes" => strlen($buffer)
			]);
		}
		return $data;
	}

	public function close(int $status=1000, string $message='kthxbye'): void {
		if (!$this->isConnected()) {
			return;
		}
		$uri = ($this->uri ?? $this->peerName);
		$statusString = \Safe\pack("n", $status);
		$this->isClosing = true;
		$this->send($statusString . $message, 'close');
		$this->logger->info("[Websocket {uri}] Closing with status: {status}", [
			"uri" => $uri,
			"status" => $status,
		]);
	}

	public function send(string $data, string $opcode='text'): void {
		if (!$this->isConnected()) {
			$this->connect();
		}
		$uri = ($this->uri ?? $this->peerName);
		$this->logger->debug("[Websocket {uri}] Sending {$opcode}", ["uri" => $uri]);
		if ($opcode === 'ping') {
			$this->pendingPingTime = time();
		}
		$this->logger->info("[Websocket {uri}] Queueing {opcode} packet", [
			"uri" => $uri,
			"opcode" => $opcode,
			"packet" => $data,
		]);

		if (!isset(static::ALLOWED_OPCODES[$opcode])) {
			$this->logger->info("[Websocket {uri}] Opcode {opcode} is invalid", [
				"uri" => $uri,
				"opcode" => $opcode,
			]);
			throw new Exception("Bad opcode '$opcode'.");
		}

		$dataChunks = str_split($data, self::FRAMESIZE);

		while (count($dataChunks)) {
			$chunk = array_shift($dataChunks);
			$final = empty($dataChunks);

			$frame = $this->toFrame($final, $chunk, $opcode, $this->maskData);
			$this->sendQueue []= $frame;

			$opcode = 'continuation';
			$this->logger->info("[Websocket {uri}] Queueing frame of packet", [
				"uri" => $uri,
			]);
		}
		$this->listenForReadWrite();
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

	protected function listenForRead(): void {
		$callback = [$this, 'onStreamActivity'];
		if (isset($this->notifier)) {
			$callback = $this->notifier->getCallback();
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		if (!is_resource($this->socket)) {
			return;
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			$callback
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	protected function listenForReadWrite(): void {
		$callback = [$this, 'onStreamActivity'];
		if (isset($this->notifier)) {
			$callback = $this->notifier->getCallback();
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		if (!is_resource($this->socket)) {
			return;
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ | SocketNotifier::ACTIVITY_WRITE,
			$callback
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	protected function listenForWebsocketReadWrite(): void {
		if (isset($this->notifier)) {
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		if (!is_resource($this->socket)) {
			return;
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ | SocketNotifier::ACTIVITY_WRITE,
			[$this, 'onStreamActivity']
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	public function setTag(string $key, mixed $value): void {
		$this->tags[$key] = $value;
	}

	public function getTag(string $key): mixed {
		return $this->tags[$key];
	}
}
