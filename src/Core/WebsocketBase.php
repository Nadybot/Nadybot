<?php declare(strict_types=1);

namespace Nadybot\Core;

use Exception;
use InvalidArgumentException;

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

	/** @var array<string,callable> */
	protected array $eventCallbacks = [];

	/** @var array<string,mixed> */
	protected $tags = [];

	protected $socket;
	protected ?string $peerName = null;
	protected int $timeout = 55;
	protected int $frameSize = 4096;
	protected bool $isClosing = false;
	protected ?string $lastOpcode = null;
	protected ?int $closeStatus = null;
	/** @var string[] */
	protected array $sendQueue = [];
	protected string $receiveBuffer = "";
	public ?SocketNotifier $notifier = null;
	protected bool $connected = false;
	protected ?int $lastReadTime = null;
	protected ?TimerEvent $timeoutChecker = null;

	public function connect(): bool {
		return true;
	}

	public function getPeer(): ?string {
		return $this->peerName;
	}

	public function on(string $event, callable $callback): self {
		if (!in_array($event, static::ALLOWED_EVENTS)) {
			throw new InvalidArgumentException("$event is not an allowed event.");
		}
		$this->eventCallbacks[$event] = $callback;
		return $this;
	}

	protected function fireEvent($eventName, WebsocketCallback $event): void {
		if (isset($this->eventCallbacks[$eventName])) {
			$this->eventCallbacks[$eventName]($event);
		}
	}

	protected function getEvent($eventName=null): WebsocketCallback {
		$eventName ??= $this->lastOpcode;
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

	public function isConnected() {
		return isset($this->socket)
			&& is_resource($this->socket)
			&& (
				get_resource_type($this->socket) === 'persistent stream'
				|| get_resource_type($this->socket) === 'stream'
			);
	}

	public function checkTimeout() {
		if (!$this->isConnected() || !$this->connected) {
			$this->throwError(
				WebsocketError::CONNECT_TIMEOUT,
				"Connecting to {$this->uri} timed out."
			);
			return;
		}
		if (time() - $this->lastReadTime >= 30) {
			$this->send("", 'ping', true);
		}
		if (time() - $this->lastReadTime >= 30 + $this->timeout) {
			$this->throwError(
				WebsocketError::CONNECT_TIMEOUT,
				"Connection to {$this->uri} timed out, no response to ping."
			);
		} else {
			$this->timeoutChecker = $this->timer->callLater(5, [$this, "checkTimeout"]);
		}
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

	protected function write(string $data): bool {
		$this->logger->log("DEBUG", "[" . ($this->uri ?? $this->peerName)."] Writing " . strlen($data) . " bytes of data to WebSocket");
		if (strlen($data) === 0) {
			return true;
		}
		$written = fwrite($this->socket, $data);
		if ($written === false) {
			$this->logger->log("ERROR", "Error sending data");
			$length = strlen($data);
			@fclose($this->socket);
			$this->throwError(
				WebsocketError::WRITE_ERROR,
				"Failed to write $length bytes to socket."
			);
			return false;
		}
		$this->logger->log("DEBUG", "$written byte sent");
		$data = substr($data, $written);
		if (strlen($data)) {
			array_unshift($this->sendQueue, $data);
		}
		$this->lastWriteTime = time();
		return true;
	}

	public function processQueue(): void {
		if (count($this->sendQueue) === 0) {
			// $this->logger->log("INFO", "[{$this->uri}] Queue empty");
			$this->listenForRead();
			return;
		}
		$packet = array_shift($this->sendQueue);
		// $this->logger->log("INFO", "Sending packet " . var_export($packet, true));
		$this->write($packet);
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
			$this->logger->log("DEBUG", "[" . ($this->uri ?? $this->peerName) . "] fragment received");
			return;
		}
		$this->logger->log(
			"DEBUG",
			"[" . ($this->uri ?? $this->peerName) . "] last fragment received, package completed"
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
				WebsocketError::BAD_OPCODE,
				"Bad opcode in websocket frame: $opcodeValue"
			);
			return [null, true];
		}
		$opcode = $opcodeValueToName[$opcodeValue];
		$this->logger->log("DEBUG", "[" . ($this->uri ?? $this->peerName) . "] $opcode received");

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
		} elseif ($opcode === 'pong') {
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

	protected function resetClient(): void {
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
					$this->logger->log("DEBUG", "Socket closed with status " . $this->closeStatus ?? "<unknown>");
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

	public function send(string $data, string $opcode='text', bool $masked=true): void {
		if (!$this->isConnected()) {
			$this->connect();
		}
		$this->logger->log("DEBUG", "[" . ($this->uri ?? $this->peerName) . "] Queueing packet");

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
			$this->logger->log("DEBUG", "[" . ($this->uri ?? $this->peerName) . "] Queueing frame of packet");
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
		// $this->logger->log('INFO', 'Modifying listener to read only');
		$callback = [$this, 'onStreamActivity'];
		if (isset($this->notifier)) {
			$callback = $this->notifier->getCallback();
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ,
			$callback
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	protected function listenForReadWrite() {
		// $this->logger->log('INFO', 'Modifying listener to rw');
		$callback = [$this, 'onStreamActivity'];
		if (isset($this->notifier)) {
			$callback = $this->notifier->getCallback();
			$this->socketManager->removeSocketNotifier($this->notifier);
		}
		$this->notifier = new SocketNotifier(
			$this->socket,
			SocketNotifier::ACTIVITY_READ | SocketNotifier::ACTIVITY_WRITE,
			$callback
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	protected function listenForWebsocketReadWrite() {
		// $this->logger->log('INFO', 'Listening for WebSocket rw');
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

	public function setTag(string $key, $value): void {
		$this->tags[$key] = $value;
	}

	public function getTag(string $key) {
		return $this->tags[$key];
	}
}
