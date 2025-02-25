<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Events\Base as EventsBase;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Types\MessageReceiver;

/**
 * This is the abstract base class of all "channels"
 * (endpoints where you can route messages to)
 */
abstract class AbstractChannel implements MessageReceiver {
	/** The name of this channel */
	abstract public function getChannelName(): string;

	/** Get the rendered message */
	protected function getEventMessage(RoutableEvent $event, MessageHub $hub, ?string $channelName=null): ?string {
		$renderPath = true;
		if ($event->getEvent() !== $event::TYPE_MESSAGE) {
			$baseEvent = $event->data??null;
			if (!isset($baseEvent) || !($baseEvent instanceof EventsBase) || !isset($baseEvent->message)) {
				return null;
			}
			$msg = $baseEvent->message;
			$renderPath = $baseEvent->renderPath;
		} else {
			$msg = $event->getData();
		}
		$channelName ??= $this->getChannelName();
		$msgColor = $hub->getTextColor($event, $channelName);
		$message = ($renderPath ? $hub->renderPath($event, $channelName) : '').
			$msgColor.$msg;
		return $message;
	}
}
