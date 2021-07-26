<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\BuddylistManager;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

class PrivateMessage implements MessageReceiver {
	/** @Inject */
	public BuddylistManager $buddyListManager;

	/** @Inject */
	public Nadybot $chatBot;

	public function getChannelName(): string {
		return Source::TELL . "(*)";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return false;
		}
		if ($this->buddyListManager->isOnline($destination)) {
			$this->chatBot->sendTell($event->getData(), $destination);
		}
		return true;
	}
}
