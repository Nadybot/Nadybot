<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

class OrgChannel implements MessageReceiver {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public MessageHub $messageHub;

	public function getChannelName(): string {
		return Source::ORG;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return false;
		}
		$msgColor = $this->messageHub->getTextColor($event);
		$message = $this->messageHub->renderPath($event).
			$msgColor.$event->getData();
		$this->chatBot->sendGuild($message, true, null, strlen($msgColor) === 0);
		return true;
	}
}
