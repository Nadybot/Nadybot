<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use Amp\Loop;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	LoggerWrapper,
	Nadybot,
	Websocket as CoreWebsocket,
	WebsocketCallback,
	WebsocketClient,
	WebsocketError,
};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
	RelayStatus,
	StatusProvider,
};

#[
	NCA\RelayTransport(
		name: "websocket",
		description:
			"You can use websockets as a relay transport.\n".
			"Websockets provide near-realtime communication, but since they\n".
			"are not part of Anarchy Online, if they are down, you might have\n".
			"a hard time debugging this.\n".
			"Websockets require a transport protocol in order to work properly\n".
			"and if they are public, you might also want to add an encryption\n".
			"layer on top of that."
	),
	NCA\Param(
		name: "server",
		type: "string",
		description: "The URI of the websocket to connect to",
		required: true
	),
	NCA\Param(
		name: "authorization",
		type: "secret",
		description: "If set, authorize against the Websocket server with a password",
		required: false
	)
]
class Websocket implements TransportInterface, StatusProvider {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public CoreWebsocket $websocket;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	protected Relay $relay;

	protected ?RelayStatus $status = null;

	protected string $uri;
	protected ?string $authorization;

	/** @var ?callable */
	protected $initCallback;

	protected WebsocketClient $client;

	public function __construct(string $uri, ?string $authorization=null) {
		$this->uri = $uri;
		$urlParts = parse_url($this->uri);
		if ($urlParts === false
			|| empty($urlParts)
			|| empty($urlParts['scheme'])
			|| empty($urlParts['host'])
		) {
			throw new Exception("Invalid URI <highlight>{$uri}<end>.");
		}
		if (!in_array($urlParts['scheme'], ['ws', 'wss'])) {
			throw new Exception("<highlight>{$urlParts['scheme']}<end> is not a valid schema. Valid are ws and wss.");
		}
		$this->authorization = $authorization;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function getStatus(): RelayStatus {
		return $this->status ?? new RelayStatus();
	}

	public function send(array $data): array {
		foreach ($data as $chunk) {
			$this->client->send($chunk);
		}
		return [];
	}

	public function processMessage(WebsocketCallback $event): void {
		if (!is_string($event->data)) {
			return;
		}
		$msg = new RelayMessage();
		$msg->packages = [$event->data];
		$this->relay->receiveFromTransport($msg);
	}

	public function processError(WebsocketCallback $event): void {
		$this->logger->error("[{$this->uri}] [Code $event->code] $event->data");
		$this->status = new RelayStatus(RelayStatus::INIT, $event->data??"Unknown state");
		if ($event->code === WebsocketError::CONNECT_TIMEOUT) {
			if (isset($this->initCallback)) {
				Loop::delay(10000, fn() => $this->client->connect());
			} else {
				unset($this->client);
				Loop::delay(10000, fn() => $this->relay->init());
			}
		} else {
			unset($this->client);
			Loop::delay(10000, fn() => $this->relay->init());
		}
	}

	public function processClose(WebsocketCallback $event): void {
		if (isset($this->initCallback)) {
			$this->logger->notice("Reconnecting to Websocket {$this->uri} in 10s.");
			$this->status = new RelayStatus(
				RelayStatus::INIT,
				"Reconnecting to {$this->uri}"
			);
			Loop::delay(10000, [$this->client, 'connect']);
		} else {
			$this->client->close();
			unset($this->client);
			$this->logger->notice("Reconnecting to Websocket {$this->uri}.");
			Loop::defer(fn() => $this->relay->init());
		}
	}

	public function processConnect(WebsocketCallback $event): void {
		$this->logger->notice("Connected to Websocket {$this->uri}.");
		if (!isset($this->initCallback)) {
			return;
		}
		$callback = $this->initCallback;
		unset($this->initCallback);
		$this->status = new RelayStatus(RelayStatus::READY, "ready");
		$callback();
	}

	public function init(callable $callback): array {
		$this->initCallback = $callback;
		$this->client = $this->websocket->createClient()
			->withURI($this->uri);
		if (isset($this->authorization)) {
			$this->client->withHeader("Authorization", $this->authorization);
		}
		$this->status = new RelayStatus(RelayStatus::INIT, "Connecting to {$this->uri}");
		$this->client->withTimeout(30)
			->on(WebsocketClient::ON_CONNECT, [$this, "processConnect"])
			->on(WebsocketClient::ON_CLOSE, [$this, "processClose"])
			->on(WebsocketClient::ON_TEXT, [$this, "processMessage"])
			->on(WebsocketClient::ON_ERROR, [$this, "processError"]);
		return [];
	}

	public function deinit(callable $callback): array {
		if (!isset($this->client) || !$this->client->isConnected()) {
			$callback();
			return [];
		}
		$closeFunc = function (WebsocketCallback $event) use ($callback): void {
			unset($this->client);
			$callback();
		};
		$this->client->on(WebsocketClient::ON_CLOSE, $closeFunc);
		$this->client->on(WebsocketClient::ON_ERROR, $closeFunc);
		$this->client->close();
		return [];
	}
}
