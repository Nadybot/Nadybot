<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Exception;
use Throwable;
use TypeError;

use Nadybot\Core\{
	Event,
	EventManager,
	LoggerWrapper,
	PacketEvent,
	Registry,
	SettingManager,
	WebsocketBase,
	WebsocketCallback,
	WebsocketServer,
};

use Nadybot\Modules\WEBSERVER_MODULE\{
	HttpProtocolWrapper,
	JsonExporter,
	Request,
	Response,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 * @ProvidesEvent("websocket(subscribe)")
 * @ProvidesEvent("websocket(request)")
 * @ProvidesEvent("websocket(response)")
 * @ProvidesEvent("websocket(event)")
 */
class WebsocketController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @var array<string,WebsocketServer> */
	protected array $clients = [];

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'websocket',
			'Enable the websocket handler',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0'
		);
	}

	/**
	 * @HttpGet("/events")
	 * @DefaultStatus("1")
	 */
	public function handleWebsocketStart(Request $request, HttpProtocolWrapper $server): void {
		if (!$this->settingManager->getBool('websocket')) {
			return;
		}
		$response = $this->getResponseForWebsocketRequest($request);
		$server->sendResponse($response);
		if ($response->code !== Response::SWITCHING_PROTOCOLS) {
			return;
		}
		$websocketHandler = new WebsocketServer($server->getAsyncSocket());
		Registry::injectDependencies($websocketHandler);
		$server->getAsyncSocket()->destroy();
		unset($server);
		$websocketHandler->serve();
		$websocketHandler->checkTimeout();
		$websocketHandler->on(WebsocketServer::ON_TEXT, [$this, "clientSentData"]);
		$websocketHandler->on(WebsocketServer::ON_CLOSE, [$this, "clientDisconnected"]);
		$websocketHandler->on(WebsocketServer::ON_ERROR, [$this, "clientError"]);

		$this->logger->log("DEBUG", "Upgrading connection to WebSocket");
	}

	/**
	 * Check if the upgrade was requested correctly and return either error or upgrade response
	 */
	protected function getResponseForWebsocketRequest(Request $request): Response {
		$errorResponse = new Response(
			Response::UPGRADE_REQUIRED,
			[
				"Connection" => "Upgrade",
				"Upgrade" => "websocket",
				"Sec-WebSocket-Version" => "13",
				// "Sec-WebSocket-Protocol" => "nadybot",
			]
		);
		$clientRequestedWebsocket = isset($request->headers["upgrade"])
			&& strtolower($request->headers["upgrade"]) === "websocket";
		if (!$clientRequestedWebsocket) {
			$this->logger->log('DEBUG', 'Client accessed WebSocket endpoint without requesting upgrade');
			return $errorResponse;
		}
		if (!isset($request->headers["sec-websocket-key"])) {
			$this->logger->log('DEBUG', 'WebSocket client did not give key');
			return new Response(Response::BAD_REQUEST);
		}
		$key = $request->headers["sec-websocket-key"];
		if (isset($request->headers["sec-websocket-protocol"])
			&& !in_array("nadybot", preg_split("/\s*,\s*/", $request->headers["sec-websocket-protocol"]))) {
			return $errorResponse;
		}

		/** @todo Validate key length and base 64 */
		$responseKey = base64_encode(pack('H*', sha1($key . WebsocketBase::GUID)));
		return new Response(
			Response::SWITCHING_PROTOCOLS,
			[
				"Connection" => "Upgrade",
				"Upgrade" => "websocket",
				"Sec-WebSocket-Accept" => $responseKey,
				// "Sec-WebSocket-Protocol" => "nadybot",
			]
		);
	}

	public function clientConnected(WebsocketCallback $event) {
		$this->logger->log("DEBUG", "New Websocket connection from " . $event->websocket->getPeer());
	}

	public function clientDisconnected(WebsocketCallback $event) {
		$this->logger->log("DEBUG", "Closed Websocket connection from " . $event->websocket->getPeer());
	}

	public function clientError(WebsocketCallback $event) {
		$this->logger->log("DEBUG", "Websocket client error from " . $event->websocket->getPeer());
		$event->websocket->close();
	}

	/**
	 * Handle the Websocket client sending data
	 */
	public function clientSentData(WebsocketCallback $event) {
		$this->logger->log("DEBUG", "[Data inc.] {$event->data}");
		try {
			if (!is_string($event->data)) {
				throw new Exception();
			}
			$data = json_decode($event->data, false, 512, JSON_THROW_ON_ERROR);
			$command = new WebsocketCommand();
			$command->fromJSON($data);
			if (!in_array($command->command, $command::ALLOWED_COMMANDS)) {
				throw new Exception();
			}
		} catch (Throwable $e) {
			$event->websocket->close(4002);
			return;
		}
		if ($command->command === $command::SUBSCRIBE) {
			$newEvent = new WebsocketSubscribeEvent();
			$newEvent->type = "websocket(subscribe)";
			$newEvent->data = new NadySubscribe();
		} elseif ($command->command === $command::REQUEST) {
			$newEvent = new WebsocketRequestEvent();
			$newEvent->type = "websocket(request)";
			$newEvent->data = new NadyRequest();
		} else {
			// Unknown command received is just silently ignored in case another handler deals with it
			return;
		}
		try {
			$newEvent->data->fromJSON($command->data);
		} catch (Throwable $e) {
			$event->websocket->close(4002);
			return;
		}
		$this->eventManager->fireEvent($newEvent, $event->websocket);
	}

	/**
	 * @Event("websocket(subscribe)")
	 * @Description("Handle Websocket event subscriptions")
	 * @DefaultStatus("1")
	 */
	public function handleSubscriptions(WebsocketSubscribeEvent $event, WebsocketServer $server): void {
		try {
			$server->subscribe(...$event->data->events);
			$this->logger->log('DEBUG', 'Websocket subscribed to ' . join(",", $event->data->events));
		} catch (TypeError $e) {
			$event->websocket->close(4002);
		}
	}

	/**
	 * @Event("websocket(request)")
	 * @Description("Handle API requests")
	 */
	public function handleRequests(WebsocketRequestEvent $event, WebsocketServer $server): void {
		// Not implemented yet
	}

	/**
	 * @Event("*")
	 * @Description("Distribute events to Websocket clients")
	 * @DefaultStatus("1")
	 */
	public function displayEvent(Event $event): void {
		$isPrivatPacket = $event->type === 'msg'
			|| $event instanceof PacketEvent
			|| $event instanceof WebsocketEvent;
		// Packages that might contain secret or private information must never be relayed
		if ($isPrivatPacket) {
			return;
		}
		$class = end($parts = explode("\\", get_class($event)));
		$event->class = $class;
		$packet = new WebsocketCommand();
		$packet->command = $packet::EVENT;
		$packet->data = $event;
		foreach ($this->clients as $uuid => $client) {
			foreach ($client->getSubscriptions() as $subscription) {
				if ($subscription === $event->type
					|| fnmatch($subscription, $event->type)) {
					$client->send(JsonExporter::encode($packet), 'text');
					$this->logger->log('INFO', 'Sending ' . $class . ' to Websocket client');
				}
			}
		}
	}

	/**
	 * Register a Websocket client connection, so we can send commands/events to it
	 */
	public function registerClient(WebsocketServer $client): void {
		$this->clients[$client->getUUID()] = $client;
	}

	/**
	 * Register a Websocket client connection, so we don't keep a reference to it
	 */
	public function unregisterClient(WebsocketServer $client): void {
		unset($this->clients[$client->getUUID()]);
	}
}
