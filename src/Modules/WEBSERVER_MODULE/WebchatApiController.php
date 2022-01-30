<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	EventManager,
	ModuleInstance,
	MessageHub,
	Nadybot,
	Registry,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	Text,
};
use Nadybot\Modules\{
	GUILD_MODULE\GuildController,
	WEBSOCKET_MODULE\WebsocketCommandReply,
};

#[NCA\Instance]
class WebchatApiController extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public GuildController $guildController;

	#[NCA\Inject]
	public CommandManager $commandManager;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Inject]
	public WebChatConverter $webChatConverter;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandManager->registerSource(Source::WEB);
	}

	/**
	 * Send a message to the org chat
	 */
	#[
		NCA\Api("/chat/web"),
		NCA\POST,
		NCA\AccessLevel("member"),
		NCA\RequestBody(class: "string", desc: "The text to send", required: true),
		NCA\ApiResult(code: 204, desc: "Message sent")
	]
	public function sendWebMessageEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$message = $request->decodedBody;
		if (!is_string($message) || !isset($request->authenticatedAs)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$event = new AOWebChatEvent();
		$event->type = "chat(web)";
		$event->channel = "web";
		$event->color = "";
		$event->path = [
			new WebSource(Source::WEB, "Web")
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

		$sendto = new WebsocketCommandReply("web");
		Registry::injectDependencies($sendto);
		$context = new CmdContext($request->authenticatedAs);
		$context->source = Source::WEB;
		$context->sendto = $sendto;
		$context->message = $message;
		$this->chatBot->getUid($context->char->name, function (?int $uid, CmdContext $context): void {
			$context->char->id = $uid;
			$this->commandManager->checkAndHandleCmd($context);
		}, $context);
		return new Response(Response::NO_CONTENT);
	}
}
