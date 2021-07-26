<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\CommandReply;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;
use Nadybot\Modules\WEBSERVER_MODULE\WebChatConverter;

class WebsocketCommandReply implements CommandReply {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public WebChatConverter $webChatConverter;

	/** @Inject */
	public EventManager $eventManager;

	protected string $type;

	public function __construct(string $type) {
		$this->type = $type;
	}

	public function reply($msg): void {
		$xmlMessage = new AOChatEvent();
		$xmlMessage->message = $this->webChatConverter->convertMessage($msg);
		$xmlMessage->sender = $this->chatBot->char->name;
		$xmlMessage->type = "chat({$this->type})";
		$xmlMessage->channel = $this->type;
		$this->eventManager->fireEvent($xmlMessage);
	}
}
