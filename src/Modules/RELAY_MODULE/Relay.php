<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\Player,
	Events\SyncEvent,
	MessageHub,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Registry,
	Routing\RoutableEvent,
	Routing\Source,
	SettingManager,
	Types\MessageReceiver,
};
use Nadybot\Modules\{
	ONLINE_MODULE\OnlinePlayer,
	RELAY_MODULE\RelayProtocol\RelayProtocolInterface,
	RELAY_MODULE\Transport\TransportInterface,
	WEBSERVER_MODULE\StatsController,
};
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

class Relay implements MessageReceiver {
	public const ALLOW_NONE = 0;
	public const ALLOW_IN = 1;
	public const ALLOW_OUT = 2;
	public bool $registerAsReceiver = true;
	public bool $registerAsEmitter = true;

	#[RelayProp('treat-online-as-guest')]
	public bool $treatOnlineAsGuest = false;

	/** @var array<string,array<string,OnlinePlayer>> */
	private array $onlineChars = [];

	/** @var array<string,RelayEvent> */
	private array $events = [];

	private bool $initialized = false;
	private int $initStep = 0;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private StatsController $statsController;

	private RelayPacketsStats $inboundPackets;
	private RelayPacketsStats $outboundPackets;

	/**
	 * @param list<RelayLayerInterface> $stack
	 * @param iterable<RelayEvent>      $events Events that this relay sends and/or receives
	 */
	public function __construct(
		public string $name,
		private TransportInterface $transport,
		private RelayProtocolInterface $relayProtocol,
		private array $stack=[],
		iterable $events=[],
		public MessageQueue $msgQueue=new MessageQueue(),
	) {
		foreach ($events as $event) {
			$this->events[$event->event] = $event;
		}
		Registry::injectDependencies($this);
		$basename = basename(str_replace('\\', '/', $relayProtocol::class));
		$this->inboundPackets = new RelayPacketsStats($basename, $this->getName(), 'in');
		$this->outboundPackets = new RelayPacketsStats($basename, $this->getName(), 'out');
		$this->statsController->registerProvider($this->inboundPackets, 'relay');
		$this->statsController->registerProvider($this->outboundPackets, 'relay');
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
		$this->logger->info('Cleaning online chars for {relay}.{where}', [
			'relay' => $this->name,
			'where' => $where,
		]);
		unset($this->onlineChars[$where]);
	}

	public function setOnline(string $clientId, string $where, string $character, ?int $uid=null, ?int $dimension=null, ?string $main=null): void {
		$this->logger->info('Marking {name} online on {relay}.{where}', [
			'name' => $character,
			'where' => $where,
			'relay' => $this->name,
			'dimension' => $dimension,
			'uid' => $uid,
		]);
		$character = ucfirst(strtolower($character));
		$this->onlineChars[$where] ??= [];
		$player = OnlinePlayer::fromPlayer(new Player(
			name: $character,
			dimension: $dimension,
			charid: $uid ?? 0,
			source: $clientId,
		));
		$player->pmain = $main ?? $character;
		$player->online = true;
		$player->afk = '';
		$this->onlineChars[$where][$character] = $player;
		EventLoop::queue(function () use ($character, $dimension, $where, $clientId): void {
			$player = $this->playerManager->byName($character, $dimension);
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
		$this->logger->info('Marking {name} offline on {relay}.{where}', [
			'name' => $character,
			'where' => $where,
			'relay' => $this->name,
			'dimension' => $dimension,
			'uid' => $uid,
		]);
		$this->onlineChars[$where] ??= [];
		unset($this->onlineChars[$where][$character]);
	}

	public function setClientOffline(string $clientId): void {
		$this->logger->info('Client {clientId} is offline on {relay}, marking all characters offline', [
			'relay' => $this->name,
			'clientId' => $clientId,
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
		$this->logger->info('Marked {numOffline} character(s) offline on {relay}', [
			'relay' => $this->name,
			'numOffline' => count($offline),
			'offline' => $offline,
			'skipped' => $skipped,
		]);
	}

	public function getStatus(): RelayStatus {
		if ($this->initialized) {
			return new RelayStatus(RelayStatus::READY, 'ready');
		}
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$this->initStep] ?? null;
		if (!isset($element)) {
			return new RelayStatus(RelayStatus::ERROR, 'unknown');
		}
		$class = $element::class;
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

	public function deinit(?callable $callback=null, int $index=0): void {
		if ($index === 0) {
			$this->logger->info('Deinitializing relay {relay}', [
				'relay' => $this->name,
			]);
			if ($this->registerAsEmitter) {
				$this->messageHub->unregisterMessageEmitter($this->getChannelName());
			}
			if ($this->registerAsReceiver) {
				$this->messageHub->unregisterMessageReceiver($this->getChannelName());
			}
		}

		/** @var list<RelayStackArraySenderInterface> */
		$layers = [
			$this->relayProtocol,
			...array_reverse($this->stack),
			$this->transport,
		];
		$layer = $layers[$index] ?? null;
		if (!isset($layer)) {
			$this->logger->info('Relay {relay} fully deinitialized', [
				'relay' => $this->name,
			]);
			if (isset($callback)) {
				$callback($this);
			}
			return;
		}
		$this->logger->info('Deinitializing layer {layer} on relay {relay}', [
			'layer' => $layer::class,
			'relay' => $this->name,
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
			$this->logger->info('Initializing relay {relay}', [
				'relay' => $this->name,
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

		/** @var list<RelayStackArraySenderInterface> */
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$index] ?? null;
		if (!isset($element)) {
			$this->initialized = true;
			$this->logger->info('Relay {relay} fully initialized', [
				'relay' => $this->name,
			]);
			if (isset($callback)) {
				$callback();
			}
			EventLoop::delay(10, function (string $token): void {
				if ($this->initialized) {
					foreach ($this->msgQueue as $message) {
						$this->receive($message, $this->getName());
					}
				}
			});
			return;
		}
		$element->setRelay($this);
		$this->logger->info('Initializing layer {layer} on relay {relay}', [
			'layer' => $element::class,
			'relay' => $this->name,
		]);
		$data = $element->init(
			function () use ($callback, $index): void {
				$this->init($callback, $index+1);
			}
		);
		if (count($data)) {
			for ($pos = $index-1; $pos >= 0; $pos--) {
				$this->logger->info('Sending init data to layer {layer} on relay {relay}', [
					'layer' => get_class($elements[$pos]),
					'relay' => $this->name,
				]);
				$data = $elements[$pos]->send($data);
			}
		}
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
		if (!count($data->packages)) {
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
			/** @psalm-suppress InvalidArrayOffset */
			$data = $this->stack[$i]->send($data);
		}
		$this->outboundPackets->inc(count($data));
		return !count($this->transport->send($data));
	}

	/** @param list<string> $data */
	public function receiveFromMember(RelayStackMemberInterface $member, array $data): void {
		$i = count($this->stack);
		if ($member !== $this->relayProtocol) {
			while ($i--) {
				/** @psalm-suppress InvalidArrayOffset */
				if ($this->stack[$i] === $member) {
					break;
				}
			}
		}
		for ($j = $i; $j--;) {
			/** @psalm-suppress InvalidArrayOffset */
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

	/** @param iterable<RelayEvent> $events */
	public function setEvents(iterable $events): void {
		$this->events = [];
		foreach ($events as $event) {
			$this->events[$event->event] = $event;
		}
	}

	/** Check id the relay protocol supports a certain feature */
	public function protocolSupportsFeature(int $feature): bool {
		return $this->relayProtocol::supportsFeature($feature);
	}

	/**
	 * Make sure either the org chat or priv channel is the first element
	 * when we send data, so it can always be traced to us
	 */
	protected function prependMainHop(RoutableEvent $event): void {
		$isOrgBot = strlen($this->config->general->orgName) > 0;
		if (count($event->path) > 0 && $event->path[0]->type !== Source::ORG && $isOrgBot) {
			$abbr = $this->settingManager->getString('relay_guild_abbreviation');
			$event->prependPath(new Source(
				Source::ORG,
				$this->config->general->orgName,
				($abbr === 'none') ? null : $abbr
			));
		} elseif (count($event->path) > 0 && $event->path[0]->type !== Source::PRIV && !$isOrgBot) {
			$event->prependPath(new Source(
				Source::PRIV,
				$this->config->main->character
			));
		}
	}
}
