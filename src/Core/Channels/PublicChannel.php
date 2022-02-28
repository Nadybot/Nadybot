<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
};

class PublicChannel extends Base {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public MessageHub $messageHub;

	protected string $channel;

	public function __construct(string $channel) {
		$this->channel = $channel;
	}

	public function getChannelName(): string {
		return Source::PUB . "({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$message = $this->getEventMessage($event, $this->messageHub);
		if (!isset($message)) {
			return false;
		}
		$this->chatBot->sendPublic($message, $this->channel);
		return true;
	}
}
