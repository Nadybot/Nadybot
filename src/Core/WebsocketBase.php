<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	Socket\ShutdownRequest,
	Socket\WriteClosureInterface,
};
use Revolt\EventLoop;

class WebsocketBase implements LogWrapInterface {
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
	public const ON_WRITE = "write";

	public const ALLOWED_EVENTS = [
		self::ON_CONNECT,
		self::ON_TEXT,
		self::ON_BINARY,
		self::ON_CLOSE,
		self::ON_PING,
		self::ON_WRITE,
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
	public ?SocketNotifier $notifier = null;
	public bool $maskData = true;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	#[NCA\Inject]
	public SocketManager $socketManager;

	/** @var array<string,callable> */
	protected array $eventCallbacks = [];

	/** @var array<string,mixed> */
	protected $tags = [];

	/**
	 * @var null|resource
	 *
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
	protected bool $connected = false;
	protected ?int $lastReadTime = null;
	protected ?int $pendingPingTime = null;
	protected ?string $timeoutHandle = null;
	protected ?string $uri=null;
	protected ?int $lastWriteTime = null;

	public function connect(): bool {
		$this->pendingPingTime = null;
		return true;
	}

	public function getPeer(): ?string {
		return $this->peerName;
	}

	public function wrapLogs(int $logLevel, string $message, array $context): array {
		$uri = ($this->uri ?? $this->peerName);
		$message = "[Websocket {uri}] " . $message;
		$context["uri"] = $uri;
		return [$logLevel, $message, $context];
	}

	/** @return static */
	public function on(string $event, callable $callback): self {
		if (!in_array($event, static::ALLOWED_EVENTS)) {
			throw new InvalidArgumentException("{$event} is not an allowed event.");
		}
		$this->eventCallbacks[$event] = $callback;
		return $this;
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
				"No data received for {noPingTime}s, ping pending since {pendingPingTime}s",
				[
					"noPingTime" => (time() - ($this->lastReadTime??0)),
					"pendingPingTime" => (time() - $this->pendingPingTime),
				]
			);
		} elseif (!isset($this->lastReadTime) || time() - $this->lastReadTime >= 30) {
			$this->logger->debug("No data received for {noPingTime}s", [
				"noPingTime" => (time() - ($this->lastReadTime??0)),
			]);
			$this->send("", 'ping');
		}
		if (isset($this->pendingPingTime) && time() - $this->pendingPingTime >= $this->timeout) {
			$this->pendingPingTime = null;
			$this->throwError(
				WebsocketError::CONNECT_TIMEOUT,
				"Connection to {$uri} timed out, no response to ping."
			);
		} else {
			$this->timeoutHandle = EventLoop::delay(5, function (string $ignore): void {
				$this->checkTimeout();
			});
		}
	}

	public function processQueue(): void {
		if (count($this->sendQueue) === 0) {
			$this->listenForRead();
			return;
		}
		$packet = array_shift($this->sendQueue);
		if (!is_string($packet)) {
			$this->logger->error("Illegal item found in send queue", ["item" => $packet]);
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
		$this->receiveBuffer .= $response[0]??"";
		// Not a complete package yet
		if (!$response[1]) {
			$this->logger->debug("fragment received", ["fragment" => $response[0]]);
			return;
		}
		if ($this->receiveBuffer === $response[0]) {
			$this->logger->debug("Package received", ["data" => $this->receiveBuffer]);
		} else {
			$this->logger->debug("Last fragment received, package complete", [
				"data" => $this->receiveBuffer,
			]);
		}
		$event = $this->getEvent();
		$event->data = $this->receiveBuffer;
		$this->receiveBuffer = "";
		if (isset($this->lastOpcode) && $this->lastOpcode !== "close") {
			$this->fireEvent($this->lastOpcode, $event);
		}
	}

	public function close(int $status=1000, string $message='kthxbye'): void {
		$this->pendingPingTime = null;
		if (!$this->isConnected()) {
			return;
		}
		$statusString = \Safe\pack("n", $status);
		$this->isClosing = true;
		$this->send($statusString . $message, 'close');
		$this->logger->info("Closing with status: {status}", [
			"status" => $status,
		]);
	}

	public function send(string $data, string $opcode='text'): void {
		if (!$this->isConnected()) {
			$this->connect();
		}
		$this->logger->debug("Sending {opcode}", ["opcode" => $opcode]);
		if ($opcode === 'ping') {
			$this->pendingPingTime = time();
		}
		$this->logger->info("Queueing {opcode} packet", [
			"opcode" => $opcode,
			"packet" => $data,
		]);

		if (!isset(static::ALLOWED_OPCODES[$opcode])) {
			$this->logger->info("Opcode {opcode} is invalid", ["opcode" => $opcode]);
			throw new Exception("Bad opcode '{$opcode}'.");
		}

		if ($data === '') {
			$dataChunks = [''];
		} else {
			$dataChunks = str_split($data, self::FRAMESIZE);
		}

		while (count($dataChunks)) {
			$chunk = array_shift($dataChunks);
			$final = empty($dataChunks);

			$frame = $this->toFrame($final, $chunk, $opcode, $this->maskData);
			$this->sendQueue []= $frame;

			$opcode = 'continuation';
			$this->logger->info("Queueing frame of packet");
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

		throw new Exception("Illegal notification ({$type}) received.");
	}

	public function setTag(string $key, mixed $value): void {
		$this->tags[$key] = $value;
	}

	public function getTag(string $key): mixed {
		return $this->tags[$key];
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
		$event = $this->getEvent();
		$event->data = $data;
		$this->fireEvent(self::ON_WRITE, $event);
		$uri = ($this->uri ?? $this->peerName);
		if (strlen($data) === 0 || !is_resource($this->socket)) {
			return true;
		}
		$this->logger->debug(
			"Writing {numBytes} bytes to websocket",
			[
				"raw" => join(" ", str_split(bin2hex($data), 2)),
				"numBytes" => strlen($data),
			]
		);
		$written = fwrite($this->socket, $data);
		if ($written === false) {
			if ((!is_resource($this->socket) || feof($this->socket)) && $this->isClosing) {
				return true;
			}
			$this->logger->error("Error sending data");
			$length = strlen($data);
			@fclose($this->socket);
			$this->throwError(
				WebsocketError::WRITE_ERROR,
				"Failed to write {$length} bytes to websocket {$uri}."
			);
			return false;
		}
		$this->logger->debug("Successfully sent {written} bytes", ["written" => $written]);

		$data = substr($data, $written);
		if (strlen($data)) {
			array_unshift($this->sendQueue, $data);
		}
		$this->lastWriteTime = time();
		return true;
	}

	/**
	 * @return array<null|int|string>
	 *
	 * @psalm-return array{0:null|string, 1:bool}
	 *
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
				"Bad opcode in websocket frame: {$opcodeValue}"
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

		$this->logger->info("Opcode {opcode} ({opcodeName}) received with {payloadLength} bytes of payload", [
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
			$this->logger->debug("Closing socket");
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
		while (strlen($data) < $length) {
			// @phpstan-ignore-next-line
			$buffer = fread($this->socket, $length - strlen($data));
			$meta = stream_get_meta_data($this->socket);
			if ($buffer === false) {
				$read = strlen($data);
				$this->resetClient();
				throw new Exception("Broken frame, read {$read} bytes out of {$length}.");
			}
			if ($meta["timed_out"] === true || $buffer === '') {
				if (feof($this->socket)) {
					@fclose($this->socket);
					$this->logger->info("Socket closed with status {status}", [
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
			$this->logger->debug("{numBytes} bytes of websocket data read", [
				"numBytes" => strlen($buffer),
			]);
		}
		return $data;
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
}
