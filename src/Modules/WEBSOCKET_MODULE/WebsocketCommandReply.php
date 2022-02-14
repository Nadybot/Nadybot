<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CommandReply,
	EventManager,
	MessageEmitter,
	MessageHub,
	Nadybot,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	AOWebChatEvent,
	WebChatConverter,
	WebSource,
};

class WebsocketCommandReply implements CommandReply, MessageEmitter {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public WebChatConverter $webChatConverter;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	protected string $type;

	public function __construct(string $type) {
		$this->type = $type;
	}

	public function getChannelName(): string {
		return Source::WEB;
	}

	public function reply($msg): void {
		$msg = (array)$msg;
		if (empty($msg)) {
			return;
		}
		foreach ($msg as $text) {
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
		$msgs = $this->webChatConverter->convertMessages($msg);
		foreach ($msgs as $msg) {
			$xmlMessage = new AOWebChatEvent();
			$xmlMessage->message = $msg;
			$xmlMessage->sender = $this->chatBot->char->name;
			$xmlMessage->type = "chat({$this->type})";
			$xmlMessage->channel = $this->type;
			$xmlMessage->path = [
				new WebSource(Source::WEB, "Web")
			];
			$xmlMessage->path[0]->renderAs = $xmlMessage->path[0]->render(null);
			$color = $this->messageHub->getHopColor($rMessage->path, Source::WEB, new Source(Source::WEB, "Web"), "tag_color");
			if (isset($color) && isset($color->tag_color)) {
				$xmlMessage->path[0]->color = $color->tag_color;
			} else {
				$xmlMessage->path[0]->color = "";
			}
			if (preg_match("/#([A-Fa-f0-9]{6})/", $this->settingManager->getString("default_routed_sys_color")??"<font>", $matches)) {
				$xmlMessage->color = $matches[1];
			}
			$this->eventManager->fireEvent($xmlMessage);
		}
	}
}
