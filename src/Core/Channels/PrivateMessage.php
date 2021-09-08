<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\BuddylistManager;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Text;

class PrivateMessage extends Base {
	/** @Inject */
	public BuddylistManager $buddyListManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
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
