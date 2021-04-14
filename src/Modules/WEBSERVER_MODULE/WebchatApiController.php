<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\CommandManager;
use Nadybot\Core\EventManager;
use Nadybot\Core\GuildChannelCommandReply;
use Nadybot\Core\Nadybot;
use Nadybot\Core\PrivateChannelCommandReply;
use Nadybot\Core\SettingManager;
use Nadybot\Core\Text;
use Nadybot\Modules\GUILD_MODULE\GuildController;

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
	public Text $text;

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
		$guestColorChannel = $this->settingManager->get("guest_color_channel");
		$guestColorGuest = $this->settingManager->get("guest_color_guest");
		$sender = $this->text->makeUserlink($request->authenticatedAs);
		$msg = "<end>{$guestColorChannel}[Web]<end> {$sender}: {$message}";
		$this->chatBot->sendGuild($msg, true);
		if ($this->settingManager->getBool('guest_relay') && count($this->chatBot->chatlist) > 0) {
			$msg = "<end>{$guestColorChannel}[Web]<end> {$guestColorGuest}{$sender}: {$message}<end>";
			$this->chatBot->sendPrivate($msg, true);
		}
		if ($message[0] === $this->settingManager->get("symbol") && strlen($message) > 1) {
			$message = substr($message, 1);
			$sendto = new GuildChannelCommandReply($this->chatBot);
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
		$sender = $this->text->makeUserlink($request->authenticatedAs);
		$guestColorChannel = $this->settingManager->get("guest_color_channel");
		$guestColorGuild = $this->settingManager->get("guest_color_guild");
		$msg = "<end>{$guestColorChannel}[Web]<end> {$guestColorGuild}{$sender}: {$message}<end>";
		$this->chatBot->sendPrivate($msg, true);
		$this->chatBot->sendGuild($msg, true);
		if ($message[0] == $this->settingManager->get("symbol") && strlen($message) > 1) {
			$message = substr($message, 1);
			$sendto = new PrivateChannelCommandReply($this->chatBot, $this->settingManager->getString('default_private_channel'));
			$this->commandManager->process("priv", $message, $request->authenticatedAs, $sendto);
		}
		return new Response(Response::NO_CONTENT);
	}
}
