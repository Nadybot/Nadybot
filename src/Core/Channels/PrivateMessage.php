<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	Blob,
	BuddylistManager,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
	Text,
	Types\AccessLevel,
};

/** This is the routing endpoint for an Anarchy Online tell-message */
class PrivateMessage extends AbstractChannel {
	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private BuddylistManager $buddyListManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private Nadybot $chatBot;

	public function getChannelName(): string {
		return Source::TELL . '(*)';
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if (substr($destination, 0, 1) === '@') {
			return $this->sendToGroup($event, substr($destination, 1));
		}
		return $this->sendToChar($event, $destination);
	}

	/**
	 * Send the given event's message to all online members
	 * with an access level of at least $group
	 *
	 * @return bool false if there was no message to send,
	 *              or the given access level doesn't exist
	 */
	private function sendToGroup(RoutableEvent $event, string $group): bool {
		$where = Source::TELL . "(@{$group})";
		$eventMessage = $this->getEventMessage($event, $this->messageHub, $where);
		if (!isset($eventMessage)) {
			return false;
		}
		$messages = (array)Blob::create($eventMessage)->render();
		$groupAL = AccessLevel::tryFrom($group);
		if (!isset($groupAL)) {
			return false;
		}
		foreach ($messages as $message) {
			foreach ($this->buddyListManager->getOnline() as $buddy) {
				if (!$this->accessManager->checkAccess($buddy, $groupAL)) {
					continue;
				}
				$this->chatBot->sendRawTell(character: $buddy, message: $message);
			}
		}
		return true;
	}

	/** Send the given event's message to the character names $destination */
	private function sendToChar(RoutableEvent $event, string $destination): bool {
		if (!$this->buddyListManager->isOnline($destination)) {
			return true;
		}
		$where = Source::TELL . "({$destination})";
		$message = $this->getEventMessage($event, $this->messageHub, $where);
		if (!isset($message)) {
			return false;
		}
		$message = $this->text->formatMessage($message);
		$this->chatBot->sendRawTell($destination, $message);
		return true;
	}
}
