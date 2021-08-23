<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use Exception;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Timer;
use Nadybot\Core\Websocket as CoreWebsocket;
use Nadybot\Core\WebsocketCallback;
use Nadybot\Core\WebsocketClient;
use Nadybot\Core\WebsocketError;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;
use Nadybot\Modules\RELAY_MODULE\RelayStatus;
use Nadybot\Modules\RELAY_MODULE\StatusProvider;

/**
 * @RelayTransport("websocket")
 * @Description("You can use websockets as a relay transport.
 * 	Websockets provide near-realtime communication, but since they
 * 	are not part of Anarchy Online, if they are down, you might have
 * 	a hard time debugging this.
 * 	Websockets require a transport protocol in order to work properly
 * 	and if they are public, you might also want to add an encryption
 * 	layer on top of that.")
 * @Param(name='server', description='The URI of the websocket to connect to', type='string', required=true)
 * @Param(name='authorization', description='If set, authorize against the Websocket server with a password', type='string', required=false)
 */
class Websocket implements TransportInterface, StatusProvider {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public CoreWebsocket $websocket;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public Timer $timer;

	protected Relay $relay;

	protected ?RelayStatus $status = null;

	protected string $uri;
	protected ?string $authorization;

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
		$msg = new RelayMessage();
		$msg->packages = [$event->data];
		$this->relay->receiveFromTransport($msg);
	}

	public function processError(WebsocketCallback $event): void {
		$this->logger->log("ERROR", "[{$this->uri}] [Code $event->code] $event->data");
		$this->status = new RelayStatus(RelayStatus::INIT, $event->data);
		if ($event->code === WebsocketError::CONNECT_TIMEOUT) {
			if (isset($this->initCallback)) {
				$this->timer->callLater(30, [$this->client, 'connect']);
			} else {
				unset($this->client);
				$this->relay->init();
			}
		}
	}

	public function processClose(WebsocketCallback $event): void {
		$this->logger->log("INFO", "Reconnecting to Websocket {$this->uri} in 10s.");
		if (isset($this->initCallback)) {
			$this->status = new RelayStatus(
				RelayStatus::INIT,
				"Reconnecting to {$this->uri}"
			);
			$this->timer->callLater(30, [$this->client, 'connect']);
		} else {
			$this->client->close();
			unset($this->client);
			$this->relay->init();
		}
	}

	public function processConnect(WebsocketCallback $event): void {
		$this->logger->log("INFO", "Connected to Websocket {$this->uri}.");
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
