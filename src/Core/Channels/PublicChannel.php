<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

class PublicChannel implements MessageReceiver {
	/** @Inject */
	public Nadybot $chatBot;

	protected string $channel;

	public function __construct(string $channel) {
		$this->channel = $channel;
	}

	public function getChannelName(): string {
		return Source::PUB . "({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return false;
		}
		$msgColor = $this->messageHub->getTextColor($event);
		$message = $this->messageHub->renderPath($event).
			$msgColor.$event->getData();
		$this->chatBot->sendPublic($message, $this->channel);
		return true;
	}
}
