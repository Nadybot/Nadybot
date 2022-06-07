<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use function Amp\Promise\rethrow;

use Nadybot\Core\{
	Attributes as NCA,
	AOChatEvent,
	BuddylistManager,
	EventManager,
	Nadybot,
	PacketEvent,
	StopExecutionException,
	UserStateEvent,
};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
};

#[
	NCA\RelayTransport(
		name: "tell",
		description:
			"This is the Anarchy Online private message (tell) protocol.\n".
			"You can use this to relay messages internally inside Anarchy Online\n".
			"via sending tells. This is the simplest form of relaying messages.\n".
			"Be aware though, that tells are rate-limited and will very likely\n".
			"lag a lot. It is also not possible to setup a relay with more\n".
			"then 2 bots this way."
	),
	NCA\Param(
		name: "bot",
		type: "string",
		description: "The name of the other bot",
		required: true
	)
]
class Tell implements TransportInterface {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	protected Relay $relay;

	protected string $bot;

	/** @var ?callable */
	protected $initCallback;

	public function __construct(string $bot) {
		$bot = ucfirst(strtolower($bot));
		$this->bot = $bot;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function send(array $data): array {
		$leftOver = [];
		foreach ($data as $chunk) {
			if (!$this->chatBot->send_tell($this->bot, $chunk)) {
				$leftOver []= $chunk;
			}
		}
		return $leftOver;
	}

	public function receiveMessage(AOChatEvent $event): void {
		if (strtolower((string)$event->sender) !== strtolower($this->bot)) {
			return;
		}
		$msg = new RelayMessage();
		$msg->packages = [$event->message];
		$msg->sender = (string)$event->sender;
		$this->relay->receiveFromTransport($msg);
		throw new StopExecutionException();
	}

	public function botOnline(UserStateEvent $event): void {
		if ($event->sender !== $this->bot) {
			return;
		}
		if (!isset($this->initCallback)) {
			return;
		}
		$callback = $this->initCallback;
		unset($this->initCallback);
		$callback();
	}

	public function botOffline(UserStateEvent $event): void {
		if ($event->sender !== $this->bot) {
			return;
		}
		if (isset($this->initCallback)) {
			return;
		}
		$this->relay->deinit(function(Relay $relay): void {
			$relay->init();
		});
	}

	public function init(callable $callback): array {
		$this->eventManager->subscribe("msg", [$this, "receiveMessage"]);
		$this->eventManager->subscribe("logon", [$this, "botOnline"]);
		$this->eventManager->subscribe("logoff", [$this, "botOffline"]);
		if ($this->buddylistManager->isOnline($this->bot)) {
			$callback();
		} else {
			$this->initCallback = $callback;
			rethrow($this->buddylistManager->addAsync(
				$this->bot,
				$this->relay->getName() . "_relay"
			));
		}
		return [];
	}

	public function deinit(callable $callback): array {
		$this->eventManager->unsubscribe("msg", [$this, "receiveMessage"]);
		$this->eventManager->unsubscribe("logon", [$this, "botOnline"]);
		$this->eventManager->unsubscribe("logoff", [$this, "botOffline"]);
		$this->buddylistManager->remove(
			$this->bot,
			$this->relay->getName() . "_relay"
		);
		$buddy = $this->buddylistManager->getBuddy($this->bot);
		if (isset($buddy) && !count($buddy->types)) {
			// We need to wait for the buddy-remove packet
			$waitForRemoval = function (PacketEvent $event) use ($callback, &$waitForRemoval): void {
				$uid = $event->packet->args[0];
				$name = $this->chatBot->lookup_user($uid);
				if ($name === $this->bot && is_int($uid)) {
					$this->buddylistManager->updateRemoved($uid);
					$this->eventManager->unsubscribe("packet(41)", $waitForRemoval);
					$callback();
				}
			};
			$this->eventManager->subscribe("packet(41)", $waitForRemoval);
		} else {
			$callback();
		}
		return [];
	}
}
