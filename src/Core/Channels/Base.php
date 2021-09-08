<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Routing\RoutableEvent;

abstract class Base implements MessageReceiver {
	abstract public function getChannelName(): string;

	protected function getEventMessage(RoutableEvent $event, MessageHub $hub, ?string $channelName=null): ?string {
		$renderPath = true;
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			if (!is_string($event->data->message??null)) {
				return null;
			}
			$msg = $event->data->message;
			$renderPath = $event->data->renderPath;
		} else {
			$msg = $event->getData();
		}
		$channelName ??= $this->getChannelName();
		$msgColor = $hub->getTextColor($event, $channelName);
		$message = ($renderPath ? $hub->renderPath($event, $channelName) : "").
			$msgColor.$msg;
		return $message;
	}
}
