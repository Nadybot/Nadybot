<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;

class PrivateChannel implements MessageReceiver {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public MessageHub $messageHub;

	protected string $channel;

	public function __construct(string $channel) {
		$this->channel = $channel;
	}

	public function getChannelName(): string {
		return Source::PRIV . "({$this->channel})";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$renderPath = true;
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			if (!is_string($event->data->message??null)) {
				return false;
			}
			$msg = $event->data->message;
			$renderPath = $event->data->renderPath;
		} else {
			$msg = $event->getData();
		}
		$msgColor = $this->messageHub->getTextColor($event, $this->getChannelName());
		$message = ($renderPath ? $this->messageHub->renderPath($event, $this->getChannelName()) : "").
			$msgColor.$msg;
		$this->chatBot->sendPrivate($message, true, $this->channel, false);
		return true;
	}
}
