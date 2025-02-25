<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
};

/** This is the routing endpoint for a private channel */
class PrivateChannel extends AbstractChannel {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private MessageHub $messageHub;

	/** @param string $channel Which channel does this represent? */
	public function __construct(protected string $channel) {
	}

	public function getChannelName(): string {
		return Source::PRIV . "({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$message = $this->getEventMessage($event, $this->messageHub);
		if (!isset($message)) {
			return false;
		}
		$this->chatBot->sendPrivate(
			message: $message,
			disableRelay: true,
			group: $this->channel,
			addDefaultColor: false,
		);
		return true;
	}
}
