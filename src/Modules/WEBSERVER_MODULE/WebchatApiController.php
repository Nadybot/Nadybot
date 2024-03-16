<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use function Amp\async;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
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
	public function sendWebMessageEndpoint(Request $request): Response {
		/** @var ?string */
		$user = $request->getAttribute(WebserverController::USER);

		/** @var ?string */
		$message = $request->getAttribute(WebserverController::BODY);
		if (!is_string($message) || !isset($user)) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$src = new WebSource(Source::WEB, "Web");
		$src->renderAs = $src->render(null);
		$color = $this->messageHub->getHopColor([$src], Source::WEB, new Source(Source::WEB, "Web"), "tag_color");
		if (isset($color, $color->tag_color)) {
			$src->color = $color->tag_color;
		} else {
			$src->color = "";
		}
		$eventColor = "";
		$color = $this->messageHub->getHopColor([$src], Source::WEB, new Source(Source::WEB, "Web"), "text_color");
		if (isset($color, $color->text_color)) {
			$eventColor = $color->text_color;
		}
		$eventMessage = $this->webChatConverter->convertMessage($message);
		$event = new AOWebChatEvent(
			channel: "web",
			color: $eventColor,
			sender: $user,
			message: $eventMessage,
			path: [
				$src,
			]
		);
		$this->eventManager->fireEvent($event);

		$rMessage = new RoutableMessage($message);
		$rMessage->setCharacter(
			new Character($user)
		);
		$rMessage->prependPath(new Source(Source::WEB, "Web"));
		$this->messageHub->handle($rMessage);

		$sendto = new WebsocketCommandReply();
		Registry::injectDependencies($sendto);
		$context = new CmdContext($user);
		$context->source = Source::WEB;
		$context->sendto = $sendto;
		$context->message = $message;
		async(function () use ($context): void {
			$uid = $this->chatBot->getUid($context->char->name);
			$context->char->id = $uid;
			$this->commandManager->checkAndHandleCmd($context);
		});
		return new Response(status: HttpStatus::NO_CONTENT);
	}
}
