<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
};

/** This is the routing endpoint for the org channel */
class OrgChannel extends AbstractChannel {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function getChannelName(): string {
		return Source::ORG;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$message = $this->getEventMessage($event, $this->messageHub);
		if (!isset($message)) {
			return false;
		}
		$this->chatBot->sendGuild(message: $message, disableRelay: true, addDefaultColor: false);
		return true;
	}
}
