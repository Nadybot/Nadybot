<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * The AsyncHttp class provides means to make HTTP and HTTPS requests.
 *
 * This class should not be instanced as it is, but instead Http class's
 * get() or post() method should be used to create instance of the
 * AsyncHttp class.
 */
class AsyncHttp {

	/** @Inject */
	public SettingObject $setting;

	/** @Inject */
	public SocketManager $socketManager;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * The URI to connect to
	 */
	private string $uri;

	/**
	 * The function to call when data has arrived
	 *
	 * @var callable $callback
	 */
	private $callback;

	/**
	 * Additional parameter to pass to the callback function
	 *
	 * @var mixed $data
	 */
	private $data;

	/**
	 * Additional headers tp send with the request
	 *
	 * @var array<string,mixed> $headers [key => value]
	 */
	private array $headers = [];

	/**
	 * Timeout after not receiving any data for $timerout seconds
	 */
	private ?int $timeout = null;

	/**
	 * The query parameters to send with out query
	 *
	 * @var string[]
	 */
	private array $queryParams = [];

	/**
	 * The raw data to send with a post request
	 *
	 * @var ?string
	 */
	private ?string $postData = null;

	/**
	 * The socket to communicate with
	 *
	 * @var resource $stream
	 */
	private $stream;

	/**
	 * The notifier to notify us when something happens in the queue
	 */
	private ?SocketNotifier $notifier;

	/**
	 * The data to send with a request
	 */
	private string $requestData = '';

	/**
	 * The incoming response data
	 */
	private string $responseData = '';

	/**
	 * The position in the $responseData where the header ends
	 *
	 * @var int|false Either a position or false if not (yet known)
	 */
	private $headersEndPos = false;

	/**
	 * The headers of the response
	 *
	 * @var string[] $responseHeaders
	 */
	private array $responseHeaders = [];

	/**
	 * The HttpRequest object
	 */
	private HttpRequest $request;

	/**
	 * An error string or false if no error
	 *
	 * @var string|false $errorString
	 */
	private $errorString = false;

	/**
	 * The timer that tracks stream timeout
	 */
	private ?TimerEvent $timeoutEvent = null;

	/**
	 * Indicates if there's still a transaction running (true) or not (false)
	 */
	private bool $finished;

	/**
	 * The event loop
	 */
	private EventLoop $loop;

	/**
	 * Override the address to connect to for integration tests
	 *
	 * @internal
	 */
	public static ?string $overrideAddress = null;

	/**
	 * Override the port to connect to for integration tests
	 *
	 * @internal
	 */
	public static ?int $overridePort = null;

	/**
	 * Create a new instance
	 */
	public function __construct(string $method, string $uri) {
		$this->method   = $method;
		$this->uri      = $uri;
		$this->finished = false;
	}

	/**
	 * Executes the HTTP query.
	 *
	 * @internal
	 */
	public function execute(): void {
		if (!$this->buildRequest()) {
			return;
		}

		$this->initTimeout();

		if (!$this->createStream()) {
			return;
		}
		if ($this->request->getScheme() === "ssl") {
			$this->activateTLS();
		} else {
			$this->setupStreamNotify();
		}

		$this->logger->log('DEBUG', "Sending request: {$this->request->getData()}");
	}

	/**
	 * Create the internal request
	 */
	private function buildRequest(): bool {
		try {
			$this->request = new HttpRequest($this->method, $this->uri, $this->queryParams, $this->headers, $this->postData);
			$this->requestData = $this->request->getData();
		} catch (InvalidHttpRequest $e) {
			$this->abortWithMessage($e->getMessage());
			return false;
		}
		return true;
	}

	/**
	 * Abort the request with the given error message
	 *
	 * @internal
	 */
	public function abortWithMessage(string $errorString): void {
		$this->setError($errorString . " for uri: '" . $this->uri . "' with params: '" . http_build_query($this->queryParams) . "'");
		$this->finish();
	}

	/**
	 * Sets error to given $errorString.
	 */
	private function setError(string $errorString): void {
		$this->errorString = $errorString;
		$this->logger->log('ERROR', $errorString);
	}

	/**
	 * Finish the transaction either as times out or regular
	 *
	 * Call the registered callback
	 */
	private function finish(): void {
		$this->finished = true;
		if ($this->timeoutEvent) {
			$this->timer->abortEvent($this->timeoutEvent);
			$this->timeoutEvent = null;
		}
		$this->close();
		$this->callCallback();
	}

	/**
	 * Removes socket notifier from bot's reactor loop and closes the stream.
	 */
	private function close(): void {
		$this->socketManager->removeSocketNotifier($this->notifier);
		$this->notifier = null;
		fclose($this->stream);
	}

	/**
	 * Calls the user supplied callback.
	 */
	private function callCallback(): void {
		if ($this->callback !== null) {
			$response = $this->buildResponse();
			call_user_func($this->callback, $response, ...$this->data);
		}
	}

	/**
	 * Return a response object
	 */
	private function buildResponse(): HttpResponse {
		$response = new HttpResponse();
		$response->request = $this->request;
		if (empty($this->errorString)) {
			$response->headers = $this->responseHeaders;
			$response->body    = $this->getResponseBody();
		} else {
			$response->error   = $this->errorString;
		}

		return $response;
	}

	/**
	 * Initialize a timer to handle timeout
	 */
	private function initTimeout(): void {
		if ($this->timeout === null) {
			$this->timeout = (int)$this->setting->http_timeout;
		}

		$this->timeoutEvent = $this->timer->callLater(
			$this->timeout,
			[$this, 'abortWithMessage'],
			"Timeout error after waiting {$this->timeout} seconds"
		);
	}

	/**
	 * Initialize the internal stream object
	 */
	private function createStream(): bool {
		$streamUri = $this->getStreamUri();
		$this->stream = stream_socket_client(
			$streamUri,
			$errno,
			$errstr,
			0,
			$this->getStreamFlags()
		);
		if ($this->stream === false) {
			$this->abortWithMessage("Failed to create socket stream, reason: $errstr ($errno)");
			return false;
		}
		$this->logger->log('DEBUG', "Stream for {$streamUri} created");
		return true;
	}

	/**
	 * Get the URI where to connect to
	 *
	 * Taking into account integration test overrides
	 */
	private function getStreamUri(): string {
		$host = self::$overrideAddress ? self::$overrideAddress : $this->request->getHost();
		$port = self::$overridePort ? self::$overridePort : $this->request->getPort();
		return "tcp://$host:$port";
	}

	/**
	 * Get the flags to set for the stream, taking Linux and Windows into account
	 */
	private function getStreamFlags(): int {
		$flags = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
		return $flags;
	}

	/**
	 * Turn on TLS as soon as we can write and then continue processing as usual
	 */
	private function activateTLS(): void {
		$this->notifier = new SocketNotifier(
			$this->stream,
			SocketNotifier::ACTIVITY_WRITE,
			function() {
				$this->logger->log('DEBUG', "Activating TLS");
				$this->socketManager->removeSocketNotifier($this->notifier);
				$sslResult = stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
				if ($sslResult === true) {
					$this->logger->log('DEBUG', "TLS crypto activated succesfully");
				} elseif ($sslResult === false) {
					$this->logger->log('ERROR', "Failed to activate TLS for the connection to ".
						$this->getStreamUri());
				} elseif ($sslResult === 0) {
					$this->logger->log('ERROR', "Failed to activate TLS for the connection to ".
						$this->getStreamUri() . " because socket was non-blocking");
				}
				$this->setupStreamNotify();
				// From here on, we can be async, but TLS handshake must be sync
				stream_set_blocking($this->stream, false);
			}
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/**
	 * Setup the event loop to notify us when something happens in the stream
	 */
	private function setupStreamNotify(): void {
		$this->notifier = new SocketNotifier(
			$this->stream,
			SocketNotifier::ACTIVITY_READ | SocketNotifier::ACTIVITY_WRITE | SocketNotifier::ACTIVITY_ERROR,
			[$this, 'onStreamActivity']
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/**
	 * Handler method which will be called when activity occurs in the SocketNotifier.
	 *
	 * @internal
	 */
	public function onStreamActivity(int $type): void {
		if ($this->finished) {
			return;
		}

		switch ($type) {
			case SocketNotifier::ACTIVITY_READ:
				$this->processResponse();
				break;

			case SocketNotifier::ACTIVITY_WRITE:
				$this->processRequest();
				break;

			case SocketNotifier::ACTIVITY_ERROR:
				$this->abortWithMessage('Socket error occurred');
				break;
		}
	}

	/**
	 * Process a received response
	 */
	private function processResponse(): void {
		$this->responseData .= $this->readAllFromSocket();

		if (!$this->isStreamClosed()) {
			return;
		}

		if (!$this->areHeadersReceived()) {
			$this->processHeaders();
		}

		if ($this->isBodyLengthKnown()) {
			if ($this->isBodyFullyReceived()) {
				$this->finish();
			} elseif ($this->isStreamClosed()) {
				$this->abortWithMessage("Stream closed before receiving all data");
			}
		} elseif ($this->isStreamClosed()) {
			$this->finish();
		}
	}

	/**
	 * Parse the headers from the received response
	 */
	private function processHeaders(): void {
		$this->headersEndPos = strpos($this->responseData, "\r\n\r\n");
		if ($this->headersEndPos !== false) {
			$headerData = substr($this->responseData, 0, $this->headersEndPos);
			$this->responseHeaders = $this->extractHeadersFromHeaderData($headerData);
		}
	}

	/**
	 * Get the response body only
	 */
	private function getResponseBody(): string {
		if ($this->headersEndPos === false) {
			return "";
		}
		return substr($this->responseData, $this->headersEndPos + 4);
	}

	/**
	 * Check if we've received any headers yet
	 */
	private function areHeadersReceived(): bool {
		return $this->headersEndPos !== false;
	}

	/**
	 * Check if our connection is closed
	 */
	private function isStreamClosed(): bool {
		return feof($this->stream);
	}

	/**
	 * Check if the whole body has been received yet
	 */
	private function isBodyFullyReceived(): bool {
		return $this->getBodyLength() <= strlen($this->getResponseBody());
	}

	/**
	 * Check if we know how many bytes to expect from the body
	 */
	private function isBodyLengthKnown(): bool {
		return $this->getBodyLength() !== null;
	}

	/**
	 * Read all data from the socket and return it
	 */
	private function readAllFromSocket(): string {
		$data = '';
		while (true) {
			$chunk = fread($this->stream, 8192);
			if ($chunk === false) {
				$this->abortWithMessage("Failed to read from the stream for uri '{$this->uri}'");
				break;
			}
			if (strlen($chunk) === 0) {
				break; // nothing to read, stop looping
			}
			$data .= $chunk;
		}

		if (!empty($data)) {
			// since data was read, reset timeout
			$this->timer->restartEvent($this->timeoutEvent);
		}

		return $data;
	}

	/**
	 * Get the length of the body or null if unknown
	 */
	private function getBodyLength(): ?int {
		if (isset($this->responseHeaders['content-length'])) {
			return intval($this->responseHeaders['content-length']);
		}
		return null;
	}

	/**
	 * Parse the received headers into an associative array [header => value]
	 */
	private function extractHeadersFromHeaderData(string $data): array {
		$headers = [];
		$lines = explode("\r\n", $data);
		[$version, $status, $statusMessage] = explode(" ", array_shift($lines), 3);
		$headers['http-version'] = $version;
		$headers['status-code'] = $status;
		$headers['status-message'] = $statusMessage;
		foreach ($lines as $line) {
			if (preg_match('/([^:]+):(.+)/', $line, $matches)) {
				$headers[strtolower(trim($matches[1]))] = trim($matches[2]);
			}
		}
		return $headers;
	}

	/**
	 * Send the request and initialize timeouts, etc.
	 */
	private function processRequest(): void {
		if (!strlen($this->requestData)) {
			return;
		}
		$written = fwrite($this->stream, $this->requestData);
		if ($written === false) {
			$this->abortWithMessage("Cannot write request headers for uri '{$this->uri}' to stream");
		} elseif ($written > 0) {
			$this->requestData = substr($this->requestData, $written);

			// since data was written, reset timeout
			$this->timer->restartEvent($this->timeoutEvent);
		}
	}

	/**
	 * Set a headers to be send with the request
	 */
	public function withHeader(string $header, $value): self {
		$this->headers[$header] = $value;
		return $this;
	}

	/**
	 * Set the request timeout
	 */
	public function withTimeout(int $timeout): self {
		$this->timeout = $timeout;
		return $this;
	}

	/**
	 * Defines a callback which will be called later on when the remote server has responded or an error has occurred.
	 *
	 * The callback has following signature:
	 * <code>function callback($response, $data)</code>
	 *  * $response - Response as an object, it has properties:
	 *                $error: error message, if any
	 *                $headers: received HTTP headers as an array
	 *                $body: received contents
	 *  * $data     - optional value which is same as given as argument to
	 *                this method.
	 */
	public function withCallback(callable $callback, ...$data): self {
		$this->callback = $callback;
		$this->data     = $data;
		return $this;
	}

	/**
	 * Set the query parameters to send with the request
	 *
	 * @param string[] $params array of key/value pair parameters passed as a query
	 */
	public function withQueryParams(array $params): self {
		$this->queryParams = $params;
		return $this;
	}

	/**
	 * Set the raw data to be sent with a post request
	 */
	public function withPostData(string $data): self {
		$this->postData = $data;
		return $this;
	}

	/**
	 * Waits until response is fully received from remote server and returns the response.
	 * Note that this blocks execution, but does not freeze the bot
	 * as the execution will return to event loop while waiting.
	 */
	public function waitAndReturnResponse(): HttpResponse {
		// run in event loop, waiting for loop->quit()
		$this->loop = new EventLoop();
		Registry::injectDependencies($this->loop);
		while (!$this->finished) {
			$this->loop->execSingleLoop();
		}

		return $this->buildResponse();
	}
}
