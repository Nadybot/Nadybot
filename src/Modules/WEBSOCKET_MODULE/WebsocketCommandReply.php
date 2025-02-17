<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSOCKET_MODULE;

use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\Types\HopColorType;
use Nadybot\Core\{
	Attributes as NCA,
	EventManager,
	MessageHub,
	Nadybot,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
	SettingManager,
	Types\CommandReply,
	Types\MessageEmitter,
};
use Nadybot\Modules\WEBSERVER_MODULE\{
	AOWebChatEvent,
	WebChatConverter,
	WebSource,
};

class WebsocketCommandReply implements CommandReply, MessageEmitter {
	protected string $type;
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private WebChatConverter $webChatConverter;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private BotConfig $config;

	public function getChannelName(): string {
		return Source::WEB;
	}

	/** @inheritDoc */
	public function reply(string|array $msg): void {
		$msg = (array)$msg;
		if (!count($msg)) {
			return;
		}

		foreach ($msg as $text) {
			$rMessage = new RoutableMessage($text);
			$rMessage->setCharacter(new Character(
				$this->config->main->character,
				$this->chatBot->char?->id
			));
			$rMessage->path = [
				new Source(Source::WEB, 'Web'),
			];
			$this->messageHub->handle($rMessage);
		}

		assert(isset($rMessage));
		$xmlMsgs = $this->webChatConverter->convertMessages($msg);
		foreach ($xmlMsgs as $xmlMsg) {
			$path = new WebSource(type: Source::WEB, name: 'Web', color: '');
			$path->renderAs = $path->render(null);
			$hopColor = $this->messageHub->getHopColor($rMessage->path, Source::WEB, new Source(Source::WEB, 'Web'), HopColorType::TagColor);
			if (isset($color, $hopColor->tag_color)) {
				$path->color = $hopColor->tag_color;
			} else {
				$path->color = '';
			}
			$color = '#FFFFFF';
			if (count($matches = Safe::pregMatch('/#([A-Fa-f0-9]{6})/', $this->settingManager->getString('default_routed_sys_color')??'<font>'))) {
				$color = $matches[1];
			}
			$xmlMessage = new AOWebChatEvent(
				message: $xmlMsg,
				sender: $this->config->main->character,
				channel: 'web',
				path: [$path],
				color: $color,
			);
			$this->eventManager->dispatch($xmlMessage);
		}
	}
}
