<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\CmdContext;
use Nadybot\Core\CommandManager;
use Nadybot\Core\EventManager;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Registry;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Text;
use Nadybot\Modules\GUILD_MODULE\GuildController;
use Nadybot\Modules\WEBSOCKET_MODULE\WebsocketCommandReply;

/**
 * @Instance
 */
class WebchatApiController {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public GuildController $guildController;

	/** @Inject */
	public CommandManager $commandManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public WebChatConverter $webChatConverter;

	/** @Inject */
	public Text $text;

	/**
	 * Send a message to the org chat
	 * @Api("/chat/web")
	 * @POST
	 * @AccessLevel("member")
	 * @RequestBody(class='string', desc='The text to send', required=true)
	 * @ApiResult(code=204, desc='Message sent')
	 */
	public function sendWebMessageEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$message = $request->decodedBody;
		$event = new AOChatEvent();
		$event->type = "chat(web)";
		$event->channel = "web";
		$event->color = "";
		$event->path = [
			new Source(Source::WEB, "Web")
		];
		$event->path[0]->renderAs = $event->path[0]->render(null);
		$color = $this->messageHub->getHopColor($event->path, Source::WEB, new Source(Source::WEB, "Web"), "tag_color");
		if (isset($color) && isset($color->tag_color)) {
			$event->path[0]->color = $color->tag_color;
		} else {
			$event->path[0]->color = "";
		}
		$color = $this->messageHub->getHopColor($event->path, Source::WEB, new Source(Source::WEB, "Web"), "text_color");
		if (isset($color) && isset($color->text_color)) {
			$event->color = $color->text_color;
		}
		$event->message = $this->webChatConverter->convertMessage($message);
		$event->sender = $request->authenticatedAs;
		$this->eventManager->fireEvent($event);

		$rMessage = new RoutableMessage($message);
		$rMessage->setCharacter(
			new Character($request->authenticatedAs)
		);
		$rMessage->prependPath(new Source(Source::WEB, "Web"));
		$this->messageHub->handle($rMessage);

		if ($message[0] === $this->settingManager->get("symbol") && strlen($message) > 1) {
			$message = substr($message, 1);
			$sendto = new WebsocketCommandReply("web");
			Registry::injectDependencies($sendto);
			$context = new CmdContext($request->authenticatedAs);
			$context->channel = "priv";
			$context->sendto = $sendto;
			$context->message = $message;
			$this->chatBot->getUid($context->char->name, function (?int $uid, CmdContext $context): void {
				$context->char->id = $uid;
				$this->commandManager->processCmd($context);
			}, $context);
		}
		return new Response(Response::NO_CONTENT);
	}
}
