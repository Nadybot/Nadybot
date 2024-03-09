<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\async;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandManager,
	EventManager,
	MessageHub,
	ModuleInstance,
	Nadybot,
	Registry,
	Routing\Character,
	Routing\RoutableMessage,
	Routing\Source,
};

use Nadybot\Modules\{
	WEBSOCKET_MODULE\WebsocketCommandReply,
};

#[NCA\Instance]
class WebchatApiController extends ModuleInstance {
	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private CommandManager $commandManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private WebChatConverter $webChatConverter;

	#[NCA\Setup]
	public function setup(): void {
		$this->commandManager->registerSource(Source::WEB);
	}

	/** Send a message to the org chat */
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
			new WebSource(Source::WEB, "Web"),
		];
		$event->path[0]->renderAs = $event->path[0]->render(null);
		$color = $this->messageHub->getHopColor($event->path, Source::WEB, new Source(Source::WEB, "Web"), "tag_color");
		if (isset($color, $color->tag_color)) {
			$event->path[0]->color = $color->tag_color;
		} else {
			$event->path[0]->color = "";
		}
		$color = $this->messageHub->getHopColor($event->path, Source::WEB, new Source(Source::WEB, "Web"), "text_color");
		if (isset($color, $color->text_color)) {
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
		async(function () use ($context): void {
			$uid = $this->chatBot->getUid($context->char->name);
			$context->char->id = $uid;
			$this->commandManager->checkAndHandleCmd($context);
		});
		return new Response(Response::NO_CONTENT);
	}
}
