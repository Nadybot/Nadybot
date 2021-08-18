<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\BuddylistManager;
use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Text;

class PrivateMessage implements MessageReceiver {
	/** @Inject */
	public BuddylistManager $buddyListManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Nadybot $chatBot;

	public function getChannelName(): string {
		return Source::TELL . "(*)";
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		$renderPath = true;
		if (!$this->buddyListManager->isOnline($destination)) {
			return true;
		}
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			if (!is_string($event->data->message??null)) {
				return false;
			}
			$msg = $event->data->message;
			$renderPath = $event->data->renderPath;
		} else {
			$msg = $event->getData();
		}
		$msgColor = $this->messageHub->getTextColor($event);
		$message = ($renderPath ? $this->messageHub->renderPath($event) : "").
			$msgColor.$msg;
		$message = $this->text->formatMessage($message);
		$this->chatBot->send_tell($destination, $message, "\0");
		return true;
	}
}
