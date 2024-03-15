<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\Routing\{RoutableEvent, Source};
use Nadybot\Core\{Attributes as NCA, EventManager, MessageHub, MessageReceiver, Safe};

use Nadybot\Modules\WEBSERVER_MODULE\{AOWebChatEvent, WebChatConverter};

class WebChannel implements MessageReceiver {
	#[NCA\Inject]
	private WebChatConverter $webChatConverter;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function getChannelName(): string {
		return Source::WEB;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return false;
		}
		$path = $this->webChatConverter->convertPath($event->getPath());
		$color = $this->messageHub->getTextColor($event, $this->getChannelName());
		if (count($matches = Safe::pregMatch("/#([A-Fa-f0-9]{6})/", $color)) === 2) {
			$color = $matches[1];
		}
		$eventChar = $event->getCharacter();
		if (!isset($eventChar)) {
			return false;
		}
		$eventData = $event->getData();
		if (!is_string($eventData)) {
			return false;
		}
		$webEvent = new AOWebChatEvent(
			channel: "web",
			path: $path,
			color: $color,
			sender: $eventChar->name,
			message: $this->webChatConverter->convertMessage($eventData),
		);

		$this->eventManager->fireEvent($webEvent);

		return true;
	}
}
