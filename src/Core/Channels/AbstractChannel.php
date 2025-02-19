<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Events\Base as EventsBase;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Types\MessageReceiver;

abstract class AbstractChannel implements MessageReceiver {
	abstract public function getChannelName(): string;

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
