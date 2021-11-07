<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\{
	DBSchema\Player,
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
};

class Relay implements MessageReceiver {
	public const ALLOW_NONE = 0;
	public const ALLOW_IN = 1;
	public const ALLOW_OUT = 2;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public PlayerManager $playerManager;

	/** Name of this relay */
	protected string $name;

	/** @var RelayLayerInterface[] */
	protected array $stack = [];

	/**
	 * Events that this relay sends and/or receives
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

	public function __construct(string $name) {
		$this->name = $name;
	}

	public function getName(): string {
		return $this->name;
	}

	/** @return array<string,array<string,OnlinePlayer>> */
	public function getOnlineList(): array {
		return $this->onlineChars;
	}

	public function clearOnline(string $where): void {
		unset($this->onlineChars[$where]);
	}

	public function setOnline(string $clientId, string $where, string $character, ?int $uid=null, ?int $dimension=null): void {
		$character = ucfirst(strtolower($character));
		$this->onlineChars[$where] ??= [];
		$player = new OnlinePlayer();
		$player->name = $character;
		$player->pmain = $character;
		$player->online = true;
		$player->afk = "";
		$player->source = $clientId;
		if (isset($uid)) {
			$player->charid = $uid;
		}
		$this->onlineChars[$where][$character] = $player;
		$this->playerManager->getByNameCallback(
			function(?Player $player) use ($where, $character, $clientId): void {
				if (!isset($player)) {
					return;
				}
				foreach ($player as $key => $value) {
					$this->onlineChars[$where][$character]->{$key} = $value;
				}
				$this->onlineChars[$where][$character]->source = $clientId;
			},
			false,
			$character,
			$dimension
		);
	}

	public function setOffline(string $sender, string $where, string $character, ?int $uid=null, ?int $dimension=null): void {
		$character = ucfirst(strtolower($character));
		$this->onlineChars[$where] ??= [];
		unset($this->onlineChars[$where][$character]);
	}

	public function setClientOffline(string $clientId): void {
		foreach ($this->onlineChars as $where => &$characters) {
			foreach ($characters as $name => $player) {
				if (($player->source??null) === null || $player->source !== $clientId) {
					continue;
				}
				unset($this->onlineChars[$where][$name]);
			}
		}
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
			$status = $element->getStatus();
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

	/**
	 * Set the stack members that make up the stack
	 */
	public function setStack(
		TransportInterface $transport,
		RelayProtocolInterface $relayProtocol,
		RelayLayerInterface ...$stack
	) {
		$this->transport = $transport;
		$this->relayProtocol = $relayProtocol;
		$this->stack = $stack;
	}

	public function deinit(?callable $callback=null, int $index=0): void {
		if ($index === 0) {
			$this->messageHub
				->unregisterMessageEmitter($this->getChannelName())
				->unregisterMessageReceiver($this->getChannelName());
		}
		/** @var RelayStackArraySenderInterface[] */
		$layers = [
			$this->relayProtocol,
			...array_reverse($this->stack),
			$this->transport
		];
		$layer = $layers[$index] ?? null;
		if (!isset($layer)) {
			if (isset($callback)) {
				$callback($this);
			}
			return;
		}
		$data = $layer->deinit(
			function() use ($callback, $index): void {
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
		$this->initialized = false;
		$this->onlineChars = [];
		$this->initStep = $index;
		$this->messageHub
			->registerMessageEmitter($this)
			->registerMessageReceiver($this);
		/** @var RelayStackArraySenderInterface[] */
		$elements = [$this->transport, ...$this->stack, $this->relayProtocol];
		$element = $elements[$index] ?? null;
		if (!isset($element)) {
			$this->initialized = true;
			if (isset($callback)) {
				$callback();
			}
			return;
		}
		$element->setRelay($this);
		$data = $element->init(
			function() use ($callback, $index): void {
				$this->init($callback, $index+1);
			}
		);
		if (count($data)) {
			for ($pos = $index-1; $pos >= 0; $pos--) {
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

	/**
	 * Handle data received from the transport layer
	 */
	public function receiveFromTransport(RelayMessage $data): void {
		foreach ($this->stack as $stackMember) {
			$data = $stackMember->receive($data);
			if (!isset($data)) {
				return;
			}
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

	/**
	 * Make sure either the org chat or priv channel is the first element
	 * when we send data, so it can always be traced to us
	 */
	protected function prependMainHop(RoutableEvent $event): void {
		$isOrgBot = strlen($this->chatBot->vars["my_guild"]??"") > 0;
		if (!empty($event->path) && $event->path[0]->type !== Source::ORG && $isOrgBot) {
			$abbr = $this->settingManager->getString("relay_guild_abbreviation");
			$event->prependPath(new Source(
				Source::ORG,
				$this->chatBot->vars["my_guild"],
				($abbr === "none") ? null : $abbr
			));
		} elseif (!empty($event->path) && $event->path[0]->type !== Source::PRIV && !$isOrgBot) {
			$event->prependPath(new Source(
				Source::PRIV,
				$this->chatBot->char->name
			));
		}
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$this->prependMainHop($event);
		$data = $this->relayProtocol->send($event);
		for ($i = count($this->stack); $i--;) {
			$data = $this->stack[$i]->send($data);
		}
		return empty($this->transport->send($data));
	}

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
}
