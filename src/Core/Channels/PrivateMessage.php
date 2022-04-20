<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
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
	public BuddylistManager $buddyListManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public Nadybot $chatBot;

	public function getChannelName(): string {
		return Source::TELL . "(*)";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if (!$this->buddyListManager->isOnline($destination)) {
			return true;
		}
		$where = Source::TELL . "({$destination})";
		$message = $this->getEventMessage($event, $this->messageHub, $where);
		if (!isset($message)) {
			return false;
		}
		$message = $this->text->formatMessage($message);
		$this->chatBot->send_tell($destination, $message);
		return true;
	}
}
