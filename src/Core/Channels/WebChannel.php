<?php declare(strict_types=1);

namespace Nadybot\Core\Channels;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\EventManager;
use Nadybot\Core\MessageHub;
use Nadybot\Core\MessageReceiver;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\Source;
use Nadybot\Modules\WEBSERVER_MODULE\AOWebChatEvent;
use Nadybot\Modules\WEBSERVER_MODULE\WebChatConverter;
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketController;

class WebChannel implements MessageReceiver {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public WebChatConverter $webChatConverter;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public WebsocketController $websocketController;

	#[NCA\Inject]
	public MessageHub $messageHub;

	public function getChannelName(): string {
		return Source::WEB;
	}

	public function receive(RoutableEvent $event, string $destination): bool {
		if ($event->getType() !== $event::TYPE_MESSAGE) {
			return false;
		}
		$webEvent = new AOWebChatEvent();
		$webEvent->path = $this->webChatConverter->convertPath($event->getPath());
		$webEvent->color = $this->messageHub->getTextColor($event, $this->getChannelName());
		if (preg_match("/#([A-Fa-f0-9]{6})/", $webEvent->color, $matches)) {
			$webEvent->color = $matches[1];
		}
		$webEvent->channel = "web";
		$eventChar = $event->getCharacter();
		if (isset($eventChar)) {
			$webEvent->sender = $eventChar->name;
		}
		$eventData = $event->getData();
		if (is_string($eventData)) {
			$webEvent->message = $this->webChatConverter->convertMessage($eventData);
		}
		$webEvent->type = "chat(web)";

		$this->eventManager->fireEvent($webEvent);

		return true;
	}
}
