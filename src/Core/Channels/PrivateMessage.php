<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
	Text,
};

class PrivateMessage extends Base {
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

	private function sendToGroup(RoutableEvent $event, string $group): bool {
		$where = Source::TELL . "(@{$group})";
		$message = $this->getEventMessage($event, $this->messageHub, $where);
		if (!isset($message)) {
			return false;
		}
		$message = $this->text->formatMessage($message);
		foreach ($this->buddyListManager->getOnline() as $buddy) {
			if (!$this->accessManager->checkAccess($buddy, $group)) {
				continue;
			}
			$this->chatBot->sendRawTell(character: $buddy, message: $message);
		}
		return true;
	}

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
