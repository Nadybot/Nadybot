<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
};

class PrivateChannel extends Base {
	protected string $channel;
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function __construct(string $channel) {
		$this->channel = $channel;
	}

	public function getChannelName(): string {
		return Source::PRIV . "({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$message = $this->getEventMessage($event, $this->messageHub);
		if (!isset($message)) {
			return false;
		}
		$this->chatBot->sendPrivate($message, true, $this->channel, false);
		return true;
	}
}
