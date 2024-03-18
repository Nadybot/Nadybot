<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use function Amp\async;
use AO\Package;
use Nadybot\Core\Event\RecvMsgEvent;
use Nadybot\Core\{
	Attributes as NCA,
	BuddylistManager,
	EventManager,
	LogoffEvent,
	LogonEvent,
	Nadybot,
	PackageEvent,
	StopExecutionException,
};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
};

#[
	NCA\RelayTransport(
		name: "tell",
		description: "This is the Anarchy Online private message (tell) protocol.\n".
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
	protected Relay $relay;

	protected string $bot;

	/** @var ?callable */
	protected $initCallback;
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

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
			if (!$this->chatBot->sendRawTell($this->bot, $chunk)) {
				$leftOver []= $chunk;
			}
		}
		return $leftOver;
	}

	public function receiveMessage(RecvMsgEvent $event): void {
		if (strtolower($event->sender) !== strtolower($this->bot)) {
			return;
		}
		$msg = new RelayMessage();
		$msg->packages = [$event->message];
		$msg->sender = $event->sender;
		$this->relay->receiveFromTransport($msg);
		throw new StopExecutionException();
	}

	public function botOnline(LogonEvent $event): void {
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

	public function botOffline(LogoffEvent $event): void {
		if ($event->sender !== $this->bot) {
			return;
		}
		if (isset($this->initCallback)) {
			return;
		}
		$this->relay->deinit(function (Relay $relay): void {
			$relay->init();
		});
	}

	public function init(callable $callback): array {
		$this->eventManager->subscribe(RecvMsgEvent::EVENT_MASK, $this->receiveMessage(...));
		$this->eventManager->subscribe(LogonEvent::EVENT_MASK, $this->botOnline(...));
		$this->eventManager->subscribe(LogoffEvent::EVENT_MASK, $this->botOffline(...));
		if ($this->buddylistManager->isOnline($this->bot)) {
			$callback();
		} else {
			$this->initCallback = $callback;
			async(
				$this->buddylistManager->addName(...),
				$this->bot,
				$this->relay->getName() . "_relay"
			);
		}
		return [];
	}

	public function deinit(callable $callback): array {
		$this->eventManager->unsubscribe(RecvMsgEvent::EVENT_MASK, $this->receiveMessage(...));
		$this->eventManager->unsubscribe(LogonEvent::EVENT_MASK, $this->botOnline(...));
		$this->eventManager->unsubscribe(LogoffEvent::EVENT_MASK, $this->botOffline(...));
		$this->buddylistManager->remove(
			$this->bot,
			$this->relay->getName() . "_relay"
		);
		$buddy = $this->buddylistManager->getBuddy($this->bot);
		if (isset($buddy) && !count($buddy->types)) {
			// We need to wait for the buddy-remove packet
			$waitForRemoval = function (PackageEvent $event) use ($callback, &$waitForRemoval): void {
				assert($event->packet->package instanceof Package\In\BuddyRemoved);
				$uid = $event->packet->package->charId;
				$name = $this->chatBot->getName($uid);
				if ($name === $this->bot) {
					$this->buddylistManager->updateRemoved($uid);
					$this->eventManager->unsubscribe(
						"packet(" . Package\Type::BuddyRemove->value . ")",
						$waitForRemoval
					);
					$callback();
				}
			};
			$this->eventManager->subscribe(
				"packet(" . Package\Type::BuddyRemove->value . ")",
				$waitForRemoval
			);
		} else {
			$callback();
		}
		return [];
	}
}
