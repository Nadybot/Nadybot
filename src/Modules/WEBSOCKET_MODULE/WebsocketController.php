<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Amp\Http\Server\{Request, Response};
use Amp\Websocket\Server\{AllowOriginAcceptor, Websocket, WebsocketClientGateway, WebsocketClientHandler, WebsocketGateway};
use Amp\Websocket\{WebsocketClient, WebsocketMessage};
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Http,
	Channels\WebChannel,
	EventManager,
	Events\PackageEvent,
	Hydrator,
	MessageHub,
	ModuleInstance,
	Registry,
	Types\Status,
};
use Nadybot\Core\Events\RecvMsgEvent;
use Nadybot\Modules\WEBSERVER_MODULE\{
	CommandReplyEvent,
	WebserverController,
};
use Nadylib\IMEX;
use Psl\Type;
use Psr\Log\LoggerInterface;
use Throwable;
use TypeError;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[NCA\Instance]
class WebsocketController extends ModuleInstance implements WebsocketClientHandler {
	/** Enable the websocket handler */
	#[NCA\Setting\Boolean]
	public bool $websocket = true;

	/** @var array<string,int> */
	protected array $clients = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private WebserverController $webserverController;

	/** @var array<int,list<string>> */
	private array $subscriptions = [];

	private readonly WebsocketGateway $gateway;

	public function __construct() {
		$this->gateway = new WebsocketClientGateway();
	}

	#[NCA\Setup]
	public function setup(): void {
		if ($this->websocket) {
			$this->registerWebChat();
		}
	}

	#[NCA\SettingChangeHandler('websocket')]
	public function changeWebsocketStatus(string $setting, string $oldValue, string $newValue, mixed $extraData): void {
		if ($newValue === '1') {
			$this->registerWebChat();
		} else {
			$this->unregisterWebChat();
		}
	}

	#[
		Http\HttpGet('/events'),
	]
	public function handleWebsocketStart(Request $request): ?Response {
		if (!$this->websocket) {
			$this->logger->notice('Websocket turned off');
			return null;
		}
		$httpServer = $this->webserverController->getServer();
		if (!isset($httpServer)) {
			$this->logger->notice('No http server found');
			return null;
		}
		$websocket = new Websocket(
			httpServer: $httpServer,
			logger: $this->logger,
			acceptor: new AllowOriginAcceptor([$request->getHeader('origin') ?? 'http://127.0.0.1:8080']),
			clientHandler: $this,
		);
		$this->logger->notice('Passing control to Websocket');
		return $websocket->handleRequest($request);
	}

	public function handleClient(WebsocketClient $client, Request $request, Response $response): void {
		$this->logger->notice('New Websocket connection from {peer}', [
			'peer' => $client->getRemoteAddress(),
		]);
		$this->gateway->addClient($client);
		$this->subscriptions[$client->getId()] = [];
		$packet = ['command' => 'uuid', 'data' => (string)$client->getId()];
		$client->sendText(IMEX\JSON::export($packet));
		while (null !== ($msg = $client->receive())) {
			try {
				$this->handleIncomingMessage($client, $msg);
			} catch (Throwable $e) {
				unset($this->subscriptions[$client->getId()]);
			}
		}
		unset($this->subscriptions[$client->getId()]);
	}

	/** Handle Websocket event subscriptions */
	#[NCA\HandlesEvent(defaultStatus: Status::Enabled)]
	public function handleSubscriptions(WebsocketSubscribeEvent $event): void {
		$client = $event->getClient();
		try {
			$this->subscriptions[$client->getId()] = $event->getData()->events;
			$this->logger->info('Websocket subscribed to {subscribed_to}', [
				'subscribed_to' => implode(',', $event->getData()->events),
			]);
		} catch (TypeError) {
			$client->close(4_002);
		}
	}

	/** Handle API requests */
	#[NCA\HandlesEvent]
	public function handleRequests(WebsocketRequestEvent $event): void {
		// Not implemented yet
	}

	/** Distribute events to Websocket clients */
	#[NCA\HandlesEvent(mask: '*', defaultStatus: Status::Enabled)]
	public function displayEvent(object $event): void {
		$isPrivatPacket = $event instanceof RecvMsgEvent
			|| $event instanceof PackageEvent
			|| $event instanceof WebsocketEvent;
		// Packages that might contain secret or private information must never be relayed
		if ($isPrivatPacket) {
			return;
		}
		$packet = new WebsocketCommand(
			command: WebsocketCommand::EVENT,
			data: $event,
		);
		$eventType = EventManager::getEventType($event);

		/** @var array<string,mixed> */
		$encodedEvent = Hydrator::literalSerialize($event);
		$encodedEvent['type'] = $eventType;
		$encodedPacket = ['command' => WebsocketCommand::EVENT, 'data' => $encodedEvent];
		foreach ($this->subscriptions as $id => $subscriptions) {
			if ($event instanceof CommandReplyEvent && $event->uuid !== (string)$id) {
				continue;
			}
			foreach ($subscriptions as $subscription) {
				if ($subscription !== $eventType && !fnmatch($subscription, $eventType)) {
					continue;
				}
				$this->gateway->sendText(IMEX\JSON::export($encodedPacket), $id);
				$this->logger->info('Sending {class} to Websocket client', [
					'class' => $event::class,
					'packet' => $packet,
				]);
			}
		}
	}

	/** Check if a Websocket client connection exists */
	public function clientExists(string $uuid): bool {
		return isset($this->subscriptions[(int)$uuid]);
	}

	protected function registerWebChat(): void {
		$wc = new WebChannel();
		Registry::injectDependencies($wc);
		$this->messageHub
			->registerMessageEmitter($wc)
			->registerMessageReceiver($wc);
	}

	protected function unregisterWebChat(): void {
		$wc = new WebChannel();
		Registry::injectDependencies($wc);
		$this->messageHub
			->unregisterMessageEmitter($wc->getChannelName())
			->unregisterMessageReceiver($wc->getChannelName());
	}

	private function handleIncomingMessage(WebsocketClient $client, WebsocketMessage $message): void {
		$body = $message->buffer();
		$this->logger->info('[Data inc.] {data}', ['data' => $body]);
		try {
			$command = Hydrator::hydrateString(WebsocketCommand::class, $body);
			if (!in_array($command->command, WebsocketCommand::ALLOWED_COMMANDS, true)) {
				throw new Exception();
			}
		} catch (Throwable) {
			$client->close(4_002);
			return;
		}

		$cmdData = $command->data;
		try {
			Type\dict(Type\string(), Type\mixed())->assert($cmdData);
		} catch (\Exception) {
			return;
		}
		if ($command->command === $command::SUBSCRIBE) {
			$newEvent = new WebsocketSubscribeEvent(
				data: Hydrator::hydrate(NadySubscribe::class, $cmdData),
				client: $client,
			);
		} elseif ($command->command === $command::REQUEST) {
			$newEvent = new WebsocketRequestEvent(
				data: Hydrator::hydrate(NadyRequest::class, $cmdData),
				client: $client,
			);
		} else {
			// Unknown command received is just silently ignored in case another handler deals with it
			return;
		}
		$this->eventManager->dispatch($newEvent);
	}
}
