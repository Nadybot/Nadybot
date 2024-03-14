<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use AO\Package;
use Nadybot\Core\{
	AOChatEvent,
	Attributes as NCA,
	EventManager,
	Nadybot,
	StopExecutionException,
};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
	RelayStatus,
	StatusProvider,
};

#[
	NCA\RelayTransport(
		name: "private-channel",
		description: "This is the Anarchy Online private channel transport.\n".
			"You can use this to relay messages internally inside Anarchy Online.\n".
			"Be aware though, that the delay is based on the size of the message\n".
			"being sent.\n".
			"The bot must be invited into the private channel before it can\n".
			"relay anything."
	),
	NCA\Param(
		name: "channel",
		type: "string",
		description: "The private channel to join",
		required: true
	)
]
class PrivateChannel implements TransportInterface, StatusProvider {
	protected Relay $relay;

	protected ?RelayStatus $status = null;

	protected string $channel;

	/** @var ?callable */
	protected $initCallback;
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventManager $eventManager;

	public function __construct(string $channel) {
		$this->channel = ucfirst(strtolower($channel));
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function getStatus(): RelayStatus {
		return $this->status ?? new RelayStatus();
	}

	public function send(array $data): array {
		$leftOver = [];
		if (null === ($uid = $this->chatBot->getUid($this->channel))) {
			return $data;
		}
		foreach ($data as $chunk) {
			$this->chatBot->aoClient->write(
				package: new Package\Out\PrivateChannelMessage(channelId: $uid, message: $chunk)
			);
		}
		return $leftOver;
	}

	public function deinit(callable $callback): array {
		$this->eventManager->unsubscribe("extpriv", [$this, "receiveMessage"]);
		$this->eventManager->unsubscribe("extJoinPrivRequest", [$this, "receiveInvite"]);
		$this->eventManager->unsubscribe("extJoinPriv", [$this, "joinedPrivateChannel"]);
		$this->eventManager->unsubscribe("otherLeavePriv", [$this, "receiveLeave"]);
		$this->eventManager->unsubscribe("extLeavePriv", [$this, "leftPrivateChannel"]);
		$callback();
		return [];
	}

	public function receiveMessage(AOChatEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$msg = new RelayMessage();
		$msg->packages []= $event->message;
		$msg->sender = $event->sender;
		$this->relay->receiveFromTransport($msg);
		throw new StopExecutionException();
	}

	public function receiveInvite(AOChatEvent $event): void {
		if (strtolower($event->sender) !== strtolower($this->channel)) {
			return;
		}
		if (null === ($uid = $this->chatBot->getUid($this->channel))) {
			return;
		}
		$this->chatBot->aoClient->write(
			package: new Package\Out\PrivateChannelJoin(channelId: $uid),
		);
	}

	public function receiveLeave(AOChatEvent $event): void {
		if (!is_string($event->sender)) {
			return;
		}
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->relay->setClientOffline($event->sender);
	}

	public function joinedPrivateChannel(AOChatEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->status = new RelayStatus(RelayStatus::READY, "ready");
		if (isset($this->initCallback)) {
			$callback = $this->initCallback;
			unset($this->initCallback);
			$callback();
		}
	}

	public function leftPrivateChannel(AOChatEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->relay->deinit(function (Relay $relay): void {
			$relay->init();
		});
	}

	public function init(callable $callback): array {
		$this->eventManager->subscribe("extpriv", [$this, "receiveMessage"]);
		$this->eventManager->subscribe("extJoinPrivRequest", [$this, "receiveInvite"]);
		$this->eventManager->subscribe("otherLeavePriv", [$this, "receiveLeave"]);
		$this->eventManager->subscribe("extLeavePriv", [$this, "leftPrivateChannel"]);
		if (!isset($this->chatBot->privateChats[$this->channel])) {
			$this->status = new RelayStatus(
				RelayStatus::INIT,
				"Waiting for invite to {$this->channel}"
			);
			// In case we have a race condition and received the invite before
			$this->initCallback = $callback;
			$this->eventManager->subscribe("extJoinPriv", [$this, "joinedPrivateChannel"]);
			if (null !== ($uid = $this->chatBot->getUid($this->channel))) {
				$this->chatBot->aoClient->write(
					package: new Package\Out\PrivateChannelJoin(channelId: $uid),
				);
			}
		} else {
			$callback();
		}
		return [];
	}
}
