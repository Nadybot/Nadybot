<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use function Amp\asyncCall;

use Amp\Loop;
use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\Player,
	LoggerWrapper,
	MessageHub,
	MessageReceiver,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
	SettingManager,
	SyncEvent,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OnlinePlayer,
	RELAY_MODULE\RelayProtocol\RelayProtocolInterface,
	RELAY_MODULE\Transport\TransportInterface,
	WEBSERVER_MODULE\StatsController,
};

class Relay implements MessageReceiver {
	public const ALLOW_NONE = 0;
	public const ALLOW_IN = 1;
	public const ALLOW_OUT = 2;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public BotConfig $config;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public StatsController $statsController;

	#[NCA\Logger]
	public LoggerWrapper $logger;
	public bool $registerAsReceiver = true;
	public bool $registerAsEmitter = true;

	public MessageQueue $msgQueue;

	/** Name of this relay */
	protected string $name;

	/**
	 * @var RelayLayerInterface[]
	 * @psalm-var list<RelayLayerInterface>
	 */
	protected array $stack = [];

	/**
	 * Events that this relay sends and/or receives
	 *
	 * @var array<string,RelayEvent>
	 */
	protected array $events = [];

	/** The transport  */
	protected TransportInterface $transport;
	protected RelayProtocolInterface $relayProtocol;

	/** @var array<string,array<string,OnlinePlayer>> */
	protected $onlineChars = [];

	protected bool $initialized = false;
	protected int $initStep = 0;

	private RelayPacketsStats $inboundPackets;
	private RelayPacketsStats $outboundPackets;

	public function __construct(string $name) {
		$this->msgQueue = new MessageQueue();
		$this->name = $name;
	}

	public function setMessageQueueSize(int $size): void {
		$this->msgQueue->setMaxLength($size);
	}

	public function getName(): string {
		return $this->name;
	}

	/** @return array<string,array<string,OnlinePlayer>> */
	public function getOnlineList(): array {
		return $this->onlineChars;
	}

	public function clearOnline(string $where): void {
		$this->logger->info("Cleaning online chars for {relay}.{where}", [
			"relay" => $this->name,
			"where" => $where,
		]);
		unset($this->onlineChars[$where]);
	}

	public function setOnline(string $clientId, string $where, string $character, ?int $uid=null, ?int $dimension=null, ?string $main=null): void {
		$this->logger->info("Marking {name} online on {relay}.{where}", [
			"name" => $character,
			"where" => $where,
			"relay" => $this->name,
			"dimension" => $dimension,
			"uid" => $uid,
		]);
		$character = ucfirst(strtolower($character));
		$this->onlineChars[$where] ??= [];
		$player = new OnlinePlayer();
		$player->name = $character;
		$player->pmain = $main ?? $character;
		$player->online = true;
		$player->afk = "";
		$player->source = $clientId;
		if (isset($uid)) {
			$player->charid = $uid;
		}
		$this->onlineChars[$where][$character] = $player;
		asyncCall(function () use ($character, $dimension, $where, $clientId): Generator {
			/** @var ?Player */
			$player = yield $this->playerManager->byName($character, $dimension);
			if (!isset($player) || !isset($this->onlineChars[$where][$character])) {
				return;
			}
			$player->source = $clientId;
			foreach (get_object_vars($player) as $key => $value) {
				$this->onlineChars[$where][$character]->{$key} = $value;
			}
		});
	}

	public function setOffline(string $sender, string $where, string $character, ?int $uid=null, ?int $dimension=null, ?string $main=null): void {
		$character = ucfirst(strtolower($character));
		$this->logger->info("Marking {name} offline on {relay}.{where}", [
			"name" => $character,
			"where" => $where,
			"relay" => $this->name,
			"dimension" => $dimension,
			"uid" => $uid,
		]);
		$this->onlineChars[$where] ??= [];
		unset($this->onlineChars[$where][$character]);
	}

	public function setClientOffline(string $clientId): void {
		$this->logger->info("Client {clientId} is offline on {relay}, marking all characters offline", [
			"relay" => $this->name,
			"clientId" => $clientId,
		]);
		$skipped = [];
		$offline = [];
		$newList = [];
		foreach ($this->onlineChars as $where => $characters) {
			foreach ($characters as $name => $player) {
				if ($player->source === $clientId) {
					$offline []= "{$where}.{$name}";
					continue;
				}
				$newList[$where] ??= [];
				$newList[$where][$name] = $player;
				$skipped []= "{$where}.{$name}";
				continue;
			}
		}
		$this->onlineChars = $newList;
		$this->logger->info("Marked {numOffline} character(s) offline on {relay}", [
			"relay" => $this->name,
			"numOffline" => count($offline),
			"offline" => $offline,
			"skipped" => $skipped,
		]);
	}

	public function getStatus(): RelayStatus {
		if ($this->initialized) {
			return new RelayStatus(RelayStatus::READY, "ready");
		}
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$this->initStep] ?? null;
		if (!isset($element)) {
			return new RelayStatus(RelayStatus::ERROR, "unknown");
		}
		$class = get_class($element);
		if (($pos = strrpos($class, '\\')) !== false) {
			$class = substr($class, $pos + 1);
		}
		if ($element instanceof StatusProvider) {
			$status = clone $element->getStatus();
			$status->text = "{$class}: {$status->text}";
			return $status;
		}
		return new RelayStatus(
			RelayStatus::INIT,
			"initializing {$class}"
		);
	}

	public function getChannelName(): string {
		return Source::RELAY . "({$this->name})";
	}

	/** Set the stack members that make up the stack */
	public function setStack(
		TransportInterface $transport,
		RelayProtocolInterface $relayProtocol,
		RelayLayerInterface ...$stack
	): void {
		/** @var RelayLayerInterface[] $stack */
		$this->transport = $transport;
		$this->relayProtocol = $relayProtocol;
		$this->stack = $stack;
		$basename = basename(str_replace('\\', '/', get_class($relayProtocol)));
		$this->inboundPackets = new RelayPacketsStats($basename, $this->getName(), "in");
		$this->outboundPackets = new RelayPacketsStats($basename, $this->getName(), "out");
		$this->statsController->registerProvider($this->inboundPackets, "relay");
		$this->statsController->registerProvider($this->outboundPackets, "relay");
	}

	public function deinit(?callable $callback=null, int $index=0): void {
		if ($index === 0) {
			$this->logger->info("Deinitializing relay {relay}", [
				"relay" => $this->name,
			]);
			if ($this->registerAsEmitter) {
				$this->messageHub->unregisterMessageEmitter($this->getChannelName());
			}
			if ($this->registerAsReceiver) {
				$this->messageHub->unregisterMessageReceiver($this->getChannelName());
			}
		}

		/** @var RelayStackArraySenderInterface[] */
		$layers = [
			$this->relayProtocol,
			...array_reverse($this->stack),
			$this->transport,
		];
		$layer = $layers[$index] ?? null;
		if (!isset($layer)) {
			$this->logger->info("Relay {relay} fully deinitialized", [
				"relay" => $this->name,
			]);
			if (isset($callback)) {
				$callback($this);
			}
			return;
		}
		$this->logger->info("Deinitializing layer {layer} on relay {relay}", [
			"layer" => get_class($layer),
			"relay" => $this->name,
		]);
		$data = $layer->deinit(
			function () use ($callback, $index): void {
				$this->deinit($callback, $index+1);
			}
		);
		if (count($data)) {
			for ($pos = $index+1; $pos < count($layers); $pos++) {
				$data = $layers[$pos]->send($data);
			}
		}
	}

	public function init(?callable $callback=null, int $index=0): void {
		if ($index === 0) {
			$this->logger->info("Initializing relay {relay}", [
				"relay" => $this->name,
			]);
		}
		$this->initialized = false;
		$this->onlineChars = [];
		$this->initStep = $index;
		if ($this->registerAsEmitter) {
			$this->messageHub->registerMessageEmitter($this);
		}
		if ($this->registerAsReceiver) {
			$this->messageHub->registerMessageReceiver($this);
		}

		/** @var RelayStackArraySenderInterface[] */
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$index] ?? null;
		if (!isset($element)) {
			$this->initialized = true;
			$this->logger->info("Relay {relay} fully initialized", [
				"relay" => $this->name,
			]);
			if (isset($callback)) {
				$callback();
			}
			Loop::delay(10000, function () {
				if ($this->initialized) {
					foreach ($this->msgQueue as $message) {
						$this->receive($message, $this->getName());
					}
				}
			});
			return;
		}
		$element->setRelay($this);
		$this->logger->info("Initializing layer {layer} on relay {relay}", [
			"layer" => get_class($element),
			"relay" => $this->name,
		]);
		$data = $element->init(
			function () use ($callback, $index): void {
				$this->init($callback, $index+1);
			}
		);
		if (count($data)) {
			for ($pos = $index-1; $pos >= 0; $pos--) {
				$this->logger->info("Sending init data to layer {layer} on relay {relay}", [
					"layer" => get_class($elements[$pos]),
					"relay" => $this->name,
				]);
				$data = $elements[$pos]->send($data);
			}
		}
	}

	public function getEventConfig(string $eventName): RelayEvent {
		$event = $this->events[$eventName] ?? null;
		if (!isset($event)) {
			$event = new RelayEvent();
			$event->event = $eventName;
		}
		return $event;
	}

	/** Handle data received from the transport layer */
	public function receiveFromTransport(RelayMessage $data): void {
		$this->inboundPackets->inc();
		foreach ($this->stack as $stackMember) {
			$data = $stackMember->receive($data);
			if (!isset($data)) {
				return;
			}
		}
		if (empty($data->packages)) {
			return;
		}
		$event = $this->relayProtocol->receive($data);
		if (!isset($event)) {
			return;
		}
		$event->prependPath(new Source(
			Source::RELAY,
			$this->name
		));
		$this->messageHub->handle($event);
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if (!$this->initialized) {
			$this->msgQueue->enqueue($event);
			return false;
		}
		$this->prependMainHop($event);
		$data = $this->relayProtocol->send($event);
		for ($i = count($this->stack); $i--;) {
			$data = $this->stack[$i]->send($data);
		}
		$this->outboundPackets->inc(count($data));
		return empty($this->transport->send($data));
	}

	/** @param string[] $data */
	public function receiveFromMember(RelayStackMemberInterface $member, array $data): void {
		$i = count($this->stack);
		if ($member !== $this->relayProtocol) {
			for ($i = count($this->stack); $i--;) {
				if ($this->stack[$i] === $member) {
					break;
				}
			}
		}
		for ($j = $i; $j--;) {
			$data = $this->stack[$j]->send($data);
		}
		$this->outboundPackets->inc(count($data));
		$this->transport->send($data);
	}

	public function allowIncSyncEvent(SyncEvent $event): bool {
		$allow = $this->events[$event->type] ?? null;
		if (!isset($allow)) {
			return false;
		}
		return $allow->incoming;
	}

	public function allowOutSyncEvent(SyncEvent $event): bool {
		$allow = $this->events[$event->type] ?? null;
		if (!isset($allow)) {
			return false;
		}
		return $allow->outgoing;
	}

	/** @param RelayEvent[] $events */
	public function setEvents(array $events): void {
		$this->events = [];
		foreach ($events as $event) {
			$this->events[$event->event] = $event;
		}
	}

	/** Check id the relay protocol supports a certain feature */
	public function protocolSupportsFeature(int $feature): bool {
		return $this->relayProtocol->supportsFeature($feature);
	}

	/**
	 * Make sure either the org chat or priv channel is the first element
	 * when we send data, so it can always be traced to us
	 */
	protected function prependMainHop(RoutableEvent $event): void {
		$isOrgBot = strlen($this->config->orgName) > 0;
		if (!empty($event->path) && $event->path[0]->type !== Source::ORG && $isOrgBot) {
			$abbr = $this->settingManager->getString("relay_guild_abbreviation");
			$event->prependPath(new Source(
				Source::ORG,
				$this->config->orgName,
				($abbr === "none") ? null : $abbr
			));
		} elseif (!empty($event->path) && $event->path[0]->type !== Source::PRIV && !$isOrgBot) {
			$event->prependPath(new Source(
				Source::PRIV,
				$this->chatBot->char->name
			));
		}
	}
}
