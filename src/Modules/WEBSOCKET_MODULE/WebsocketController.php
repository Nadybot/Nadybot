<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Exception;
use Throwable;
use TypeError;
use Nadybot\Core\{
	CommandReply,
	Event,
	EventManager,
	LoggerWrapper,
	PacketEvent,
	Registry,
	SettingManager,
	WebsocketEvent,
	WebsocketServer,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 * @ProvidesEvent("websocket(subscribe)")
 * @ProvidesEvent("websocket(request)")
 * @ProvidesEvent("websocket(response)")
 * @ProvidesEvent("websocket(event)")
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'webauth',
 *		accessLevel = 'mod',
 *		description = 'Pre-authorize Websocket connections',
 *		help        = 'webauth.txt'
 *	)
 */
class WebsocketController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	public WebsocketServer $server;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'certificate_path',
			'Path to the SSL/TLS certificate',
			'edit',
			'text',
			''
		);

		$this->server = new WebsocketServer();
		Registry::injectDependencies($this->server);
		$this->server
			->on(WebsocketServer::ON_CONNECT, [$this, "clientConnected"])
			->on(WebsocketServer::ON_TEXT, [$this, "clientSentData"])
			->on(WebsocketServer::ON_CLOSE, [$this, "clientDisconnected"]);
		$this->server->listen();
	}

	public function clientConnected(WebsocketEvent $event) {
		$this->logger->log("INFO", "New connection from " . $event->websocket->getPeer());
	}

	public function clientDisconnected(WebsocketEvent $event) {
		$this->logger->log("INFO", "Closed connection from " . $event->websocket->getPeer());
	}

	public function clientSentData(WebsocketEvent $event) {
		$this->logger->log("INFO", "[Data inc.] {$event->data}");
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
			$newEvent->websocket = $event->websocket;
			$newEvent->type = "websocket(subscribe)";
			$newEvent->data = new NadySubscribe();
			$newEvent->data->fromJSON($command->data);
			$this->eventManager->fireEvent($newEvent);
		} elseif ($command->command === $command::REQUEST) {
			$newEvent = new WebsocketRequestEvent();
			$newEvent->websocket = $event->websocket;
			$newEvent->type = "websocket(request)";
			$newEvent->data = new NadyRequest();
			$newEvent->data->fromJSON($command->data);
			$this->eventManager->fireEvent($newEvent);
		}
	}

	/**
	 * @Event("websocket(subscribe)")
	 * @Description("Handle API event subscriptions")
	 */
	public function handleSubscriptions(WebsocketSubscribeEvent $event): void {
		try {
			$event->websocket->subscribe(...$event->data->events);
		} catch (TypeError $e) {
			$event->websocket->close(4002);
		}
	}

	/**
	 * @Event("websocket(request)")
	 * @Description("Handle API requests")
	 */
	public function handleRequests(WebsocketRequestEvent $event): void {
	}

	/**
	 * @Event("timer(1min)")
	 * @Description("Ping WebSocket clients")
	 */
	public function sendPing(): void {
		if (isset($this->server)) {
			$this->server->send("", "ping");
		}
	}

	/**
	 * @Event("*")
	 * @Description("Sink")
	 * @DefaultStatus("1")
	 */
	public function displayEvent(Event $event): void {
		if ($event instanceof PacketEvent) {
			return;
		}
		$class = end($parts = explode("\\", get_class($event)));
		$event->class = $class;
		$clients = $this->server->getClients();
		$packet = new WebsocketCommand();
		$packet->command = $packet::EVENT;
		$packet->data = $event;
		foreach ($clients as $uuid => $client) {
			foreach ($client->getSubscriptions() as $subscription) {
				if ($subscription === $event->type
					|| fnmatch($subscription, $event->type)) {
					$client->send(json_encode($packet, JSON_UNESCAPED_SLASHES));
					$this->logger->log('DEBUG', 'Sending ' . $class . ' to Websocket client');
				}
			}
		}
	}
	/**
	 * @Event("timer(10min)")
	 * @Description("Remove expired authorizations")
	 */
	public function clearExpiredAuthorizations(): void {
		$this->server->clearExpiredAuthorizations();
	}

	/**
	 * @HandlesCommand("webauth")
	 * @Matches("/^webauth$/")
	 */
	public function webauthCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$uuid = $this->server->authorize($sender, 3600);
		$msg = "You can now authorize to the Websocket server for 1h with the ".
			"crediantials <highlight>{$uuid}<end>.";
		$sendto->reply($msg);
	}
}
