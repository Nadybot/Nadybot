<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\CommandReply;
use Nadybot\Core\EventManager;
use Nadybot\Core\MessageEmitter;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SettingManager;
use Nadybot\Modules\WEBSERVER_MODULE\WebChatConverter;

class WebsocketCommandReply implements CommandReply, MessageEmitter {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public WebChatConverter $webChatConverter;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public MessageHub $messageHub;

	protected string $type;

	public function __construct(string $type) {
		$this->type = $type;
	}

	public function getChannelName(): string {
		return Source::WEB;
	}

	public function reply($msg): void {
		foreach ((array)$msg as $text) {
			$rMessage = new RoutableMessage($text);
			$rMessage->setCharacter(new Character(
				$this->chatBot->char->name,
				$this->chatBot->char->id
			));
			$rMessage->path = [
				new Source(Source::WEB, "Web")
			];
			$this->messageHub->handle($rMessage);
		}
		$msgs = $this->webChatConverter->convertMessages((array)$msg);
		foreach ($msgs as $msg) {
			$xmlMessage = new AOChatEvent();
			$xmlMessage->message = $msg;
			$xmlMessage->sender = $this->chatBot->char->name;
			$xmlMessage->type = "chat({$this->type})";
			$xmlMessage->channel = $this->type;
			$xmlMessage->path = [
				new Source(Source::WEB, "Web")
			];
			$xmlMessage->path[0]->renderAs = $xmlMessage->path[0]->render(null);
			$color = $this->messageHub->getHopColor(Source::WEB, Source::WEB, "Web", "tag_color");
			if (isset($color) && isset($color->tag_color)) {
				$xmlMessage->path[0]->color = $color->tag_color;
			} else {
				$xmlMessage->path[0]->color = "";
			}
			if (preg_match("/#([A-Fa-f0-9]{6})/", $this->settingManager->getString("default_routed_sys_color"), $matches)) {
				$xmlMessage->color = $matches[1];
			}
			$this->eventManager->fireEvent($xmlMessage);
		}
	}
}
