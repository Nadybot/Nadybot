<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\{
	Attributes as NCA,
	Blob,
	MessageHub,
	Nadybot,
	Routing\RoutableEvent,
	Routing\Source,
};

class PrivateChannel extends Base {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private MessageHub $messageHub;

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
		$message = Blob::create($message)->render(formatMessage: false);
		$this->chatBot->sendPrivate($message, true, $this->channel, false);
		return true;
	}
}
