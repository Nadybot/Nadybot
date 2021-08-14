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
		if (!$this->buddyListManager->isOnline($destination)) {
			return true;
		}
		$msgColor = $this->messageHub->getTextColor($event);
		$message = $this->messageHub->renderPath($event).
			$msgColor.$event->getData();
		$this->chatBot->sendTell($message, $destination, null, strlen($msgColor) === 0);
		return true;
	}
}
