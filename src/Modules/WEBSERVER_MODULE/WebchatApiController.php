<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\AOChatEvent;
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
		$event->type = "web";
		$event->channel = "web";
		$event->message = $message;
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
			$this->commandManager->process("priv", $message, $request->authenticatedAs, $sendto);
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Send a message to the org chat
	 * @Api("/chat/org")
	 * @POST
	 * @AccessLevel("guild")
	 * @RequestBody(class='string', desc='The text to send', required=true)
	 * @ApiResult(code=204, desc='Message sent')
	 * @ApiResult(code=404, desc='Not an org bot')
	 */
	public function sendOrgMessageEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$message = $request->decodedBody;
		if (!$this->guildController->isGuildBot()) {
			return new Response(Response::NOT_FOUND);
		}
		$event = new AOChatEvent();
		$event->type = "web";
		$event->channel = $this->chatBot->vars["my_guild"];
		$event->message = $message;
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
			$sendto = new WebsocketCommandReply("guild");
			Registry::injectDependencies($sendto);
			$this->commandManager->process("guild", $message, $request->authenticatedAs, $sendto);
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Send a message to the priv chat
	 * @Api("/chat/priv")
	 * @POST
	 * @AccessLevel("member")
	 * @RequestBody(class='string', desc='The text to send', required=true)
	 * @ApiResult(code=204, desc='Message sent')
	 */
	public function sendPrivMessageEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$message = $request->decodedBody;
		$event = new AOChatEvent();
		$event->type = "web";
		$event->channel = $this->chatBot->vars["name"];
		$event->message = $message;
		$event->sender = $request->authenticatedAs;
		$this->eventManager->fireEvent($event);

		$rMessage = new RoutableMessage($message);
		$rMessage->setCharacter(
			new Character($request->authenticatedAs)
		);
		$rMessage->prependPath(new Source(Source::WEB, "Web"));
		$this->messageHub->handle($rMessage);

		if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
			$message = substr($message, 1);
			$sendto = new WebsocketCommandReply("priv");
			Registry::injectDependencies($sendto);
			$this->commandManager->process("priv", $message, $request->authenticatedAs, $sendto);
		}
		return new Response(Response::NO_CONTENT);
	}
}
