<?php

namespace Budabot\Core;

use stdClass;

/**
 * The AsyncHttp class provides means to make HTTP and HTTPS requests.
 *
 * This class should not be instanced as it is, but instead Http class's
 * get() or post() method should be used to create instance of the
 * AsyncHttp class.
 */
class AsyncHttp {

	/**
	 * @var \Budabot\Core\SettingObject $setting
	 * @Inject
	 */
	public $setting;

	/**
	 * @var \Budabot\Core\SocketManager $socketManager
	 * @Inject
	 */
	public $socketManager;

	/**
	 * @var \Budabot\Core\Timer $timer
	 * @Inject
	 */
	public $timer;

	/**
	 * @var \Budabot\Core\LoggerWrapper $logger
	 * @Logger
	 */
	public $logger;

	/**
	 * The URI to connect to
	 *
	 * @var string $uri
	 */
	private $uri;

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
	 * @var string[] $headers [key => value]
	 */
	private $headers = array();
	/**
	 * Timeout after not receiving any data for $timerout seconds
	 *
	 * @var int|null $timeout
	 */
	private $timeout = null;

	/**
	 * The query parameters to send with out query
	 *
	 * @var string[]
	 */
	private $queryParams = array();

	/**
	 * The socket to communicate with
	 *
	 * @var resource $stream
	 */
	private $stream;

	/**
	 * The notifier to notify us when something happens in the queue
	 *
	 * @var \Budabot\Core\SocketNotifier $notifier
	 */
	private $notifier;

	/**
	 * The data to send with a request
	 *
	 * @var string $requestData
	 */
	private $requestData = '';

	/**
	 * The incoming response data
	 *
	 * @var string $responseData
	 */
	private $responseData = '';
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
	private $responseHeaders = array();

	/**
	 * The HttpRequest object
	 *
	 * @var \Budabot\Core\HttpRequest $request
	 */
	private $request;

	/**
	 * An error string or false if no error
	 *
	 * @var string|false $errorString
	 */
	private $errorString = false;

	/**
	 * The timer that tracks stream timeout
	 *
	 * @var \Budabot\Core\Timer $timeoutEvent
	 */
	private $timeoutEvent = null;

	/**
	 * Indicates if there's still a transaction running (true) or not (false)
	 *
	 * @var bool $finished
	 */
	private $finished;

	/**
	 * The event loop
	 *
	 * @var \Budabot\Core\EventLoop $loop
	 */
	private $loop;

	/**
	 * Override the address to connect to for integration tests
	 *
	 * @internal
	 * @var string|null $overrideAddress
	 */
	public static $overrideAddress = null;

	/**
	 * Override the port to connect to for integration tests
	 *
	 * @internal
	 * @var int|null $overridePort
	 */
	public static $overridePort    = null;

	/**
	 * Create a new instance
	 *
	 * @internal
	 * @param string $method http method to use (get/post)
	 * @param string $uri    URI which should be requested
	 */
	public function __construct($method, $uri) {
		$this->method   = $method;
		$this->uri      = $uri;
		$this->finished = false;
	}

	/**
	 * Executes the HTTP query.
	 *
	 * @internal
	 * @return void
	 */
	public function execute() {
		if (!$this->buildRequest()) {
			return;
		}

		$this->initTimeout();

		if (!$this->createStream()) {
			return;
		}
		$this->setupStreamNotify();

		$this->logger->log('DEBUG', "Sending request: {$this->request->getData()}");
	}

	/**
	 * Create the internal request
	 *
	 * @return bool true on success, else false
	 */
	private function buildRequest() {
		try {
			$this->request = new HttpRequest($this->method, $this->uri, $this->queryParams, $this->headers);
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
	 * @param string $errorString The error message to give
	 * @return void
	 */
	public function abortWithMessage($errorString) {
		$this->setError($errorString . " for uri: '" . $this->uri . "' with params: '" . http_build_query($this->queryParams) . "'");
		$this->finish();
	}

	/**
	 * Sets error to given $errorString.
	 *
	 * @param string $errorString error string
	 * @return void
	 */
	private function setError($errorString) {
		$this->errorString = $errorString;
		$this->logger->log('ERROR', $errorString);
	}

	/**
	 * Finish the transaction either as times out or regular
	 *
	 * Call the registered callback
	 *
	 * @return void
	 */
	private function finish() {
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
	 *
	 * @return void
	 */
	private function close() {
		$this->socketManager->removeSocketNotifier($this->notifier);
		$this->notifier = null;
		fclose($this->stream);
	}

	/**
	 * Calls the user supplied callback.
	 *
	 * @return void
	 */
	private function callCallback() {
		if ($this->callback !== null) {
			$response = $this->buildResponse();
			call_user_func($this->callback, $response, $this->data);
		}
	}

	/**
	 * Return a response object
	 *
	 * @return \StdClass
	 */
	private function buildResponse() {
		$response = new StdClass();
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
	 *
	 * @return void
	 */
	private function initTimeout() {
		if ($this->timeout === null) {
			$this->timeout = $this->setting->http_timeout;
		}

		$this->timeoutEvent = $this->timer->callLater(
			$this->timeout,
			array($this, 'abortWithMessage'),
			"Timeout error after waiting {$this->timeout} seconds"
		);
	}

	/**
	 * Initialize the internal stream object
	 *
	 * @return bool true on success, otherwise false
	 */
	private function createStream() {
		$this->stream = stream_socket_client($this->getStreamUri(), $errno, $errstr, 10, $this->getStreamFlags());
		if ($this->stream === false) {
			$this->abortWithMessage("Failed to create socket stream, reason: $errstr ($errno)");
			return false;
		}
		stream_set_blocking($this->stream, 0);
		return true;
	}

	/**
	 * Get the URI where to connect to
	 *
	 * Taking into account integraation test overrides
	 *
	 * @return string The URI like http://my.host.name:123
	 */
	private function getStreamUri() {
		$scheme = $this->request->getScheme();
		$host = self::$overrideAddress ? self::$overrideAddress : $this->request->getHost();
		$port = self::$overridePort ? self::$overridePort : $this->request->getPort();
		return "$scheme://$host:$port";
	}

	/**
	 * Get the flags to set for the stream, taking Linux and Windows into account
	 *
	 * @return int A bitfield with flags
	 */
	private function getStreamFlags() {
		$flags = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
		// don't use asynchronous stream on Windows with SSL
		// see bug: https://bugs.php.net/bug.php?id=49295
		if ($this->request->getScheme() == 'ssl' && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			$flags = STREAM_CLIENT_CONNECT;
		}
		return $flags;
	}

	/**
	 * Setup the event loop to notify us when something happens in the stream
	 *
	 * @return void
	 */
	private function setupStreamNotify() {
		$this->notifier = new SocketNotifier(
			$this->stream,
			SocketNotifier::ACTIVITY_READ | SocketNotifier::ACTIVITY_WRITE | SocketNotifier::ACTIVITY_ERROR,
			array($this, 'onStreamActivity')
		);
		$this->socketManager->addSocketNotifier($this->notifier);
	}

	/**
	 * Handler method which will be called when activity occurs in the SocketNotifier.
	 *
	 * @internal
	 * @param int $type type of activity, see SocketNotifier::ACTIVITY_* constants.
	 * @return void
	 */
	public function onStreamActivity($type) {
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
	 *
	 * @return void
	 */
	private function processResponse() {
		$this->responseData .= $this->readAllFromSocket();

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
	 *
	 * @return void
	 */
	private function processHeaders() {
		$this->headersEndPos = strpos($this->responseData, "\r\n\r\n");
		if ($this->headersEndPos !== false) {
			$headerData = substr($this->responseData, 0, $this->headersEndPos);
			$this->responseHeaders = $this->extractHeadersFromHeaderData($headerData);
		}
	}

	/**
	 * Get the response body only
	 *
	 * @return string The response body
	 */
	private function getResponseBody() {
		return substr($this->responseData, $this->headersEndPos + 4);
	}

	/**
	 * Check if we've received any headers yet
	 *
	 * @return bool
	 */
	private function areHeadersReceived() {
		return $this->headersEndPos !== false;
	}

	/**
	 * Check if our connection is closed
	 *
	 * @return boolean
	 */
	private function isStreamClosed() {
		return feof($this->stream);
	}

	/**
	 * Check if the whole body has been received yet
	 *
	 * @return boolean
	 */
	private function isBodyFullyReceived() {
		return $this->getBodyLength() <= strlen($this->getResponseBody());
	}

	/**
	 * Check if we know how many bytes to expect from the body
	 *
	 * @return boolean
	 */
	private function isBodyLengthKnown() {
		return $this->getBodyLength() !== null;
	}

	/**
	 * Read all data from the socket and return it
	 *
	 * @return string The read data
	 */
	private function readAllFromSocket() {
		$data = '';
		while (true) {
			$chunk = fread($this->stream, 8192);
			if ($chunk === false) {
				$this->abortWithMessage("Failed to read from the stream for uri '{$this->uri}'");
				break;
			}
			if (strlen($chunk) == 0) {
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
	 *
	 * @return int|null The length of the body or null if unknown
	 */
	private function getBodyLength() {
		return isset($this->responseHeaders['content-length']) ? intval($this->responseHeaders['content-length']) : null;
	}

	/**
	 * Parse the received headers into an associative array [header => value]
	 *
	 * @param string $data The received data
	 * @return string[]
	 */
	private function extractHeadersFromHeaderData($data) {
		$headers = array();
		$lines = explode("\r\n", $data);
		list($version, $status, $statusMessage) = explode(" ", array_shift($lines), 3);
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
	 *
	 * @return void
	 */
	private function processRequest() {
		if ($this->requestData) {
			$written = fwrite($this->stream, $this->requestData);
			if ($written === false) {
				$this->abortWithMessage("Cannot write request headers for uri '{$this->uri}' to stream");
			} elseif ($written > 0) {
				$this->requestData = substr($this->requestData, $written);

				// since data was written, reset timeout
				$this->timer->restartEvent($this->timeoutEvent);
			}
		}
	}

	/**
	 * Set a headers to be send with the request
	 *
	 * @param string $header Header name
	 * @param string $value Header value
	 * @return $this
	 */
	public function withHeader($header, $value) {
		$this->headers[$header] = $value;
		return $this;
	}

	/**
	 * Set the request timeout
	 *
	 * @param int $timeout The timeout in seconds
	 * @return $this
	 */
	public function withTimeout($timeout) {
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
	 *
	 * @param callable $callback callback which will be called when request is done
	 * @param mixed    $data     extra data which will be passed as second argument to the callback
	 * @return $this
	 */
	public function withCallback($callback, $data=null) {
		$this->callback = $callback;
		$this->data     = $data;
		return $this;
	}

	/**
	 * Set the query parameters to send with the request
	 *
	 * @param string[] $params array of key/value pair parameters passed as a query
	 * @return $this
	 */
	public function withQueryParams($params) {
		$this->queryParams = $params;
		return $this;
	}

	/**
	 * Waits until response is fully received from remote server and returns the response.
	 * Note that this blocks execution, but does not freeze the bot
	 * as the execution will return to event loop while waiting.
	 *
	 * @return \StdClass
	 */
	public function waitAndReturnResponse() {
		// run in event loop, waiting for loop->quit()
		$this->loop = new EventLoop();
		Registry::injectDependencies($this->loop);
		while (!$this->finished) {
			$this->loop->execSingleLoop();
		}

		return $this->buildResponse();
	}
}
