<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use DateTime;
use Exception;
use Nadybot\Core\{
	EventManager,
	LegacyLogger,
	LoggerWrapper,
	Socket\AsyncSocket,
};
use stdClass;
use Throwable;

/**
 * A convenient wrapper around AsyncSockets that emits
 * http(get) and http(post) events
 *
 * @ProvidesEvent("http(get)")
 * @ProvidesEvent("http(head)")
 * @ProvidesEvent("http(post)")
 * @ProvidesEvent("http(put)")
 * @ProvidesEvent("http(delete)")
 * @ProvidesEvent("http(patch)")
 */
class HttpProtocolWrapper {
	public const EXPECT_REQUEST = 1;
	public const EXPECT_HEADER = 2;
	public const EXPECT_BODY = 3;
	public const EXPECT_DONE = 4;
	public const EXPECT_IGNORE = 5;

	protected AsyncSocket $asyncSocket;
	protected Request $request;

	/** @Inject */
	public WebserverController $webserverController;

	/** @Inject */
	public EventManager $eventManager;

	/** @Logger */
	public LoggerWrapper $logger;

	protected string $readQueue = "";
	protected int $nextPart = self::EXPECT_REQUEST;

	/**
	 * Wrap an AsyncSocket with a HttpProtocolWrapper
	 */
	public function wrapAsyncSocket(AsyncSocket $asyncSocket): self {
		$this->logger->log('DEBUG', 'New AsyncSocket socket assigned to HttpProtocolWrapper');
		$this->request = new Request();
		$this->asyncSocket = $asyncSocket;
		$this->asyncSocket->on(AsyncSocket::DATA, [$this, "handleIncomingData"]);
		$this->asyncSocket->on(AsyncSocket::CLOSE, [$this, "handleConnectionClosed"]);
		return $this;
	}

	/**
	 * Get the AsyncSocket that is wrapper with this HttpProtocolWrapper
	 */
	public function getAsyncSocket(): AsyncSocket {
		return $this->asyncSocket;
	}

	/**
	 * Deal with a closing connection
	 */
	public function handleConnectionClosed(AsyncSocket $socket): void {
		unset($this->asyncSocket);
	}

	/**
	 * Depending on what we've read so far, read a new token, parse it or emit an event
	 * @throws Exception on unknown state
	 */
	public function handleIncomingData(AsyncSocket $socket): void {
		$this->logger->log('TRACE', 'Data available for read');
		if ($this->asyncSocket->getState() !== $this->asyncSocket::STATE_READY) {
			fread($socket->getSocket(), 4096);
			return;
		}
		switch ($this->nextPart) {
			case self::EXPECT_REQUEST:
				$this->logger->log('TRACE', 'Expecting REQUEST line');
				$this->readRequestLine($socket);
				break;
			case self::EXPECT_HEADER:
				$this->logger->log('TRACE', 'Expecting HEADER line');
				$this->readHeaderLine($socket);
				break;
			case self::EXPECT_BODY:
				$this->logger->log('TRACE', 'Expecting BODY data');
				$this->readBody($socket);
				break;
			case self::EXPECT_IGNORE:
				@fread($socket->getSocket(), 4096);
				break;
			case self::EXPECT_DONE:
				break;
			default:
				throw new Exception("Invalid state {$this->nextPart} encountered.");
		}
		if ($this->nextPart === static::EXPECT_DONE) {
				$this->logger->log('DEBUG', 'Request fully received');
				$this->handleRequest();
		}
	}

	/**
	 * Read a \r\n terminated line from the socket and return it
	 * @param AsyncSocket $socket The socket to read from
	 * @return null|string The read line or null on error
	 */
	protected function readSocketLine(AsyncSocket $socket): ?string {
		$lowSocket = $socket->getSocket();
		$buffer = fgets($lowSocket, 4098);
		if ($buffer === false) {
			$this->logger->log('DEBUG', 'Error reading a line from socket: ' . error_get_last());
			$socket->close();
			return null;
		}
		$trimmedData = rtrim($buffer);
		// This can be cost intensive to calculate, so only do it if really needed
		if ($this->logger->isEnabledFor('TRACE')) {
			$this->logger->log(
				'TRACE',
				'Read data: ' . $this->dataToLogString($buffer)
			);
		}
		if (strlen($buffer) > 4096) {
			$this->logger->log('DEBUG', 'Line was longer than the allowed length of 4096 bytes');
			$this->httpError(new Response(Response::REQUEST_HEADER_FIELDS_TOO_LARGE));
			return null;
		}
		return $trimmedData;
	}

	protected function dataToLogString(string $data): string {
		return '"' . preg_replace_callback(
			"/[^\x20-\x7E]/",
			function(array $match): string {
				if ($match[0] === "\r") {
					return "\\r";
				}
				if ($match[0] === "\n") {
					return "\\n";
				}
				return "\\" . sprintf("%02X", ord($match[0]));
			},
			$data
		) . '"';
	}

	/**
	 * Send a HTTP error message and gently close the socket
	 */
	public function httpError(Response $response): void {
		$this->sendResponse($response, true);
	}

	/**
	 * Send a response back to the connected client
	 */
	public function sendResponse(Response $response, bool $forceClose=false): void {
		$this->logger->log('DEBUG', 'Received a ' . $response->code . ' (' . $response->codeString . ') response');
		if (isset($this->request->replied)) {
			$this->logger->log('DEBUG', 'Not sending response, because already responded to');
			return;
		}
		$dataChanged = $this->checkIfResponseDataChanged($response);
		if ($dataChanged === false) {
			$this->logger->log('DEBUG', 'Client already has the latest version');
			if (in_array($this->request->method, [Request::GET, Request::HEAD], true)) {
				$response->code = Response::NOT_MODIFIED;
				unset($response->headers['Content-Length']);
				unset($response->headers['Content-Type']);
			} else {
				$response->code = Response::PRECONDITION_FAILED;
				$response->headers['Content-Length'] = 0;
			}
			$response->codeString = Response::DEFAULT_RESPONSE_TEXT[$response->code];
			$this->logger->log('DEBUG', "Changing reply to {$response->code} ({$response->codeString})");
			$response->body = null;
		}
		if (isset($this->request->method) && $this->request->method === Request::HEAD) {
			$this->logger->log('DEBUG', 'Removing body, because we received a HEAD request');
			$response->body = null;
			if ($response->code !== $response::OK) {
				$response->headers["Content-Length"] = null;
				unset($response->headers["Content-Type"]);
			}
		}
		$requiresClose = $forceClose
			|| ( $this->request->version < 1.1
				&& strtolower($this->request->headers['connection']??"") !== "keep-alive")
			|| (strtolower($this->request->headers["connection"]??"")) === "close"
			|| $response->code >= 400;
		if (!$requiresClose) {
			if ($response->code !== Response::SWITCHING_PROTOCOLS) {
				$response->headers['Connection'] = 'Keep-Alive';
				$response->headers['Keep-Alive'] = 'timeout=' . $this->asyncSocket->getTimeout();
			}
		} else {
			$response->headers['Connection'] = 'Close';
			$this->logger->log('DEBUG', 'Not allowing keep-alives for this client/response.');
		}
		$this->logger->log('DEBUG', 'Sending response');
		$responseString = $response->toString($this->request);
		$this->asyncSocket->write($responseString);
		$this->request->replied = microtime(true);
		if ($requiresClose) {
			$this->logger->log('DEBUG', 'Closing socket connection');
			$this->asyncSocket->close();
		}
	}

	/**
	 * Check if the client already has the latest version of the data
	 */
	protected function checkIfResponseDataChanged(Response $response): bool {
		if (isset($this->request->headers['if-modified-since']) && isset($response->headers['Last-Modified'])) {
			$clientVersion = DateTime::createFromFormat(
				DateTime::RFC7231,
				$this->request->headers['if-modified-since']
			);
			$serverVersion = DateTime::createFromFormat(
				DateTime::RFC7231,
				$response->headers['Last-Modified']
			);
			if ($clientVersion->getTimestamp() >= $serverVersion->getTimestamp()) {
				return false;
			}
		}
		if (isset($this->request->headers['if-none-match']) && isset($response->headers['ETag'])) {
			$tags = preg_split("/\s*,\s*/", $this->request->headers['if-none-match']);
			foreach ($tags as $tag) {
				if ($tag === '*' || $tag === $response->headers['ETag']) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Read the "GET / http/1.0" line or generate errors on parse errors
	 */
	public function readRequestLine(AsyncSocket $socket): void {
		$line = $this->readSocketLine($socket);
		if ($line === null) {
			return;
		}
		// Try to detect raw SSL data in case the server is not running SSL/TLS
		if (strlen($line) > 5 && ord($line[0]) === 0x16 && ord($line[5]) === 0x01) {
			$this->logger->log('DEBUG', "SSL connection for non-SSL socket detected");
			// Empty the socket data, send a close and ignore all further replies
			@fread($socket->getSocket(), 4096);
			$socket->close();
			$this->nextPart = static::EXPECT_IGNORE;
			return;
		}
		if (!preg_match("/^([a-za-z]+)\s+(.+)\s+http\/([0-9.]+)$/i", $line, $matches)) {
			$this->httpError(new Response(Response::BAD_REQUEST));
			$this->nextPart = static::EXPECT_IGNORE;
			return;
		}
		$this->request->method = strtolower($matches[1]);
		if (!in_array($this->request->method, [Request::GET, Request::POST, Request::HEAD, Request::PUT, Request::PATCH, Request::DELETE])) {
			$this->httpError(new Response(Response::NOT_IMPLEMENTED));
			return;
		}
		$parts = parse_url($matches[2]);
		if ($parts === false || $parts === null) {
			$this->httpError(new Response(Response::BAD_REQUEST));
			$this->nextPart = static::EXPECT_IGNORE;
			return;
		}
		$this->request->path = urldecode($parts["path"] ?? "/");
		if (isset($parts["query"])) {
			$queryParts = explode("&", $parts["query"]);
			$this->request->query = array_reduce(
				$queryParts,
				function(array $carry, $newPart): array {
					$kv = explode("=", $newPart, 2);
					$kv = array_map("urldecode", $kv);
					$carry[$kv[0]] = $kv[1] ?? null;
					return $carry;
				},
				[]
			);
		}
		$this->request->version = (float)$matches[3];
		$this->nextPart = static::EXPECT_HEADER;
	}

	/**
	 * Read a HTTP header line from the socket and parse it
	 */
	public function readHeaderLine(AsyncSocket $socket): void {
		$line = $this->readSocketLine($socket);
		if ($line === null) {
			return;
		}
		if ($line !== '') {
			$parts = preg_split("/\s*:\s*/", $line, 2);
			if (count($parts) !== 2) {
				$this->httpError(new Response(Response::BAD_REQUEST));
				return;
			}
			$key = strtolower($parts[0]);
			$this->request->headers[$key] = $parts[1];
			return;
		}
		if (!isset($this->request->headers['content-length'])
			|| !is_numeric($this->request->headers['content-length'])) {
			if (in_array($this->request->method, [Request::GET, Request::HEAD, Request::DELETE], true)) {
				$this->nextPart = static::EXPECT_DONE;
				$this->request->received = microtime(true);
				return;
			}
			$this->httpError(new Response(Response::LENGTH_REQUIRED));
			return;
		}
		if ($this->request->headers['content-length'] === '0') {
			$this->nextPart = static::EXPECT_DONE;
			$this->request->received = microtime(true);
			return;
		}
		if ($this->request->headers['content-length'] > 1024 * 1024) {
			$this->httpError(new Response(Response::PAYLOAD_TOO_LARGE));
			return;
		}
		$this->nextPart = static::EXPECT_BODY;
	}

	/**
	 * Read the body of the POST request, if necessary in chunks
	 */
	public function readBody(AsyncSocket $socket): void {
		$toRead = (int)$this->request->headers['content-length'] - strlen($this->request->body ?? "");
		$readChunk = min(4096, $toRead);
		$this->logger->log('TRACE', "Reading {$readChunk} bytes");
		$buffer = fread($socket->getSocket(), $readChunk);
		if ($buffer === false) {
			$this->logger->log('DEBUG', "Error reading body from socket: " . error_get_last());
			$socket->close();
			return;
		}
		$this->request->body ??= "";
		$this->request->body .= $buffer;
		if ($toRead === strlen($buffer)) {
			$this->nextPart = static::EXPECT_DONE;
			$this->request->received = microtime(true);
			$this->logger->log('TRACE', 'Body fully read');
			if ($this->logger->isEnabledFor('TRACE')) {
				$this->logger->log(
					'TRACE',
					'Read data: ' . $this->dataToLogString($this->request->body)
				);
			}
		}
	}

	/**
	 * Return the username for which this connection as authorized or null if unauthorized
	 */
	protected function getAuthenticatedUser(): ?string {
		if (!isset($this->request->headers["authorization"])) {
			return null;
		}
		$parts = preg_split("/\s+/", $this->request->headers["authorization"]);
		if (count($parts) !== 2 || strtolower($parts[0]) !== 'basic') {
			return null;
		}
		$userPassString = base64_decode($parts[1]);
		if ($userPassString === false) {
			return null;
		}
		$userPass = explode(":", $userPassString, 2);
		if (count($userPass) !== 2) {
			return null;
		}
		$authenticatedUser = $this->webserverController->checkAuthentication($userPass[0], $userPass[1]);
		if ($authenticatedUser === null) {
			return null;
		}
		return $authenticatedUser;
	}

	/**
	 * Trigger the event handlers for the request
	 */
	public function handleRequest(): void {
		$this->request->authenticatedAs = $this->getAuthenticatedUser();
		$event = new HttpEvent();
		$response = $this->decodeRequestBody($this->request);
		if ($response instanceof Response) {
			$this->httpError($response);
			return;
		}
		$event->request = $this->request;
		$event->type = "http(" . strtolower($this->request->method) . ")";
		$this->logger->log("DEBUG", "Firing {$event->type} event");
		$this->eventManager->fireEvent($event, $this);
		$this->request = new Request();
		$this->nextPart = static::EXPECT_REQUEST;
		$this->readQueue = "";
	}

	/**
	 * Try and decode the request body in accordance of the given content type
	 * @return null|Response null on success or a error Response to send
	 */
	public function decodeRequestBody(): ?Response {
		if (!isset($this->request->body) || $this->request->body === "") {
			return null;
		}
		if (!isset($this->request->headers['content-type'])) {
			return new Response(Response::UNSUPPORTED_MEDIA_TYPE);
		}
		if (preg_split("/;\s*/", $this->request->headers['content-type'])[0] === 'application/json') {
			try {
				$this->request->decodedBody = json_decode($this->request->body, false, 512, JSON_THROW_ON_ERROR);
				return null;
			} catch (Throwable $error) {
				return new Response(Response::BAD_REQUEST, [], "Invalid JSON given: ".$error->getMessage());
			}
		}
		if (preg_split("/;\s*/", $this->request->headers['content-type'])[0] === 'application/x-www-form-urlencoded') {
			$parts = explode("&", $this->request->body);
			$result = new stdClass();
			foreach ($parts as $part) {
				$kv = array_map("urldecode", explode("=", $part, 2));
				$result->{$kv[0]} = $kv[1] ?? null;
			}
			$this->request->decodedBody = $result;
			return null;
		}
		return new Response(Response::UNSUPPORTED_MEDIA_TYPE);
	}

	public function __destruct() {
		LegacyLogger::log('TRACE', 'HttpProtocolWrapper', get_class() . ' destroyed');
	}
}
