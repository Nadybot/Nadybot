<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use AO\{Package, Utils};
use Nadybot\Core\{
	Attributes as NCA,
	EventManager,
	Exceptions\StopExecutionException,
	Nadybot,
};
use Nadybot\Core\Events\{ExtJoinPrivRequest, JoinPrivEvent, LeavePrivEvent, OtherLeavePrivEvent, PrivateChannelMsgEvent};
use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
	RelayStatus,
	RelayStatusType,
	StatusProvider,
};

/**
 * This is the Anarchy Online private channel transport.
 * You can use this to relay messages internally inside Anarchy Online.
 * Be aware though, that the delay is based on the size of the message
 * being sent.
 * The bot must be invited into the private channel before it can
 * relay anything.
 */
#[NCA\RelayTransport(name: 'private-channel')]
class PrivateChannel implements TransportInterface, StatusProvider {
	protected Relay $relay;

	protected ?RelayStatus $status = null;

	protected string $channel;

	/** @var ?callable */
	protected mixed $initCallback;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private EventManager $eventManager;

	/** @param string $channel The private channel to join */
	public function __construct(
		#[NCA\Param] string $channel,
	) {
		$this->channel = Utils::normalizeCharacter($channel);
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
			$this->chatBot->sendPackage(
				package: new Package\Out\PrivateChannelMessage(channelId: $uid, message: $chunk)
			);
		}
		return $leftOver;
	}

	public function deinit(callable $callback): array {
		$this->eventManager->unsubscribe('extpriv', $this->receiveMessage(...));
		$this->eventManager->unsubscribe('extjoinpriv', $this->receiveInvite(...));
		$this->eventManager->unsubscribe('extJoinPriv', $this->joinedPrivateChannel(...));
		$this->eventManager->unsubscribe('otherLeavePriv', $this->receiveLeave(...));
		$this->eventManager->unsubscribe('extLeavePriv', $this->leftPrivateChannel(...));
		$callback();
		return [];
	}

	public function receiveMessage(PrivateChannelMsgEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$msg = new RelayMessage(
			packages: [$event->message],
			sender: $event->sender,
		);
		$this->relay->receiveFromTransport($msg);
		throw new StopExecutionException();
	}

	public function receiveInvite(ExtJoinPrivRequest $event): void {
		if (strtolower($event->sender) !== strtolower($this->channel)) {
			return;
		}
		if (null === ($uid = $this->chatBot->getUid($this->channel))) {
			return;
		}
		$this->chatBot->sendPackage(
			package: new Package\Out\PrivateChannelJoin(channelId: $uid),
		);
	}

	public function receiveLeave(OtherLeavePrivEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->relay->setClientOffline($event->sender);
	}

	public function joinedPrivateChannel(JoinPrivEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->status = new RelayStatus(RelayStatusType::READY, 'ready');
		if (isset($this->initCallback)) {
			$callback = $this->initCallback;
			$this->initCallback = null;
			$callback();
		}
	}

	public function leftPrivateChannel(LeavePrivEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->relay->deinit(static function (Relay $relay): void {
			$relay->init();
		});
	}

	public function init(callable $callback): array {
		$this->eventManager->subscribe('extpriv', $this->receiveMessage(...));
		$this->eventManager->subscribe('extJoinPrivRequest', $this->receiveInvite(...));
		$this->eventManager->subscribe('otherLeavePriv', $this->receiveLeave(...));
		$this->eventManager->subscribe('extLeavePriv', $this->leftPrivateChannel(...));
		if (!$this->chatBot->isInPrivateChannel($this->channel)) {
			$this->status = new RelayStatus(
				RelayStatusType::INIT,
				"Waiting for invite to {$this->channel}"
			);
			// In case we have a race condition and received the invite before
			$this->initCallback = $callback;
			$this->eventManager->subscribe('extJoinPriv', $this->joinedPrivateChannel(...));
			if (null !== ($uid = $this->chatBot->getUid($this->channel))) {
				$this->chatBot->sendPackage(
					package: new Package\Out\PrivateChannelJoin(channelId: $uid),
				);
			}
		} else {
			$callback();
		}
		return [];
	}
}
