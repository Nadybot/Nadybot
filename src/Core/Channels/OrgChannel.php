<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

class OrgChannel implements MessageReceiver {
	/** @Inject */
	public Nadybot $chatBot;

	public function getChannelName(): string {
		return Source::ORG;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() === $event::TYPE_MESSAGE) {
			$this->chatBot->sendGuild($event->getData(), true);
			return true;
		}
		// TODO: process online events
	}
}
