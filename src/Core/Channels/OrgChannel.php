<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
};

class OrgChannel extends Base {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public MessageHub $messageHub;

	public function getChannelName(): string {
		return Source::ORG;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$message = $this->getEventMessage($event, $this->messageHub);
		if (!isset($message)) {
			return false;
		}
		$this->chatBot->sendGuild($message, true, null, false);
		return true;
	}
}
