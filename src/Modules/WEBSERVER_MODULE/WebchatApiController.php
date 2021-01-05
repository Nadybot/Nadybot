<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Nadybot;
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
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/**
	 * Send a message to the org chat
	 * @Api("/chat/org")
	 * @POST
	 * @AccessLevel("guild")
	 * @RequestBody(class='String', desc='The text to send', required=true)
	 * @ApiResult(code=204, desc='Message sent')
	 * @ApiResult(code=404, desc='Not an org bot')
	 */
	public function sendOrgMessageEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$message = $request->decodedBody;
		if (!$this->guildController->isGuildBot()) {
			return new Response(Response::NOT_FOUND);
		}
		$guestColorChannel = $this->settingManager->get("guest_color_channel");
		$guestColorGuest = $this->settingManager->get("guest_color_guest");
		$sender = $this->text->makeUserlink($request->authenticatedAs);
		$msg = "<end>{$guestColorChannel}[Web]<end> {$sender}: {$message}";
		$this->chatBot->sendGuild($msg, true);
		if ($this->settingManager->getBool('guest_relay') && count($this->chatBot->chatlist) > 0) {
			$msg = "<end>{$guestColorChannel}[Web]<end> {$guestColorGuest}{$sender}: {$message}<end>";
			$this->chatBot->sendPrivate($msg, true);
		}
		return new Response(Response::NO_CONTENT);
	}

	/**
	 * Send a message to the priv chat
	 * @Api("/chat/priv")
	 * @POST
	 * @AccessLevel("member")
	 * @RequestBody(class='String', desc='The text to send', required=true)
	 * @ApiResult(code=204, desc='Message sent')
	 */
	public function sendPrivMessageEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$message = $request->decodedBody;
		$sender = $this->text->makeUserlink($request->authenticatedAs);
		$guestColorChannel = $this->settingManager->get("guest_color_channel");
		$guestColorGuild = $this->settingManager->get("guest_color_guild");
		$msg = "<end>{$guestColorChannel}[Web]<end> {$guestColorGuild}{$sender}: {$message}<end>";
		$this->chatBot->sendPrivate($msg, true);
		$this->chatBot->sendGuild($msg, true);
		return new Response(Response::NO_CONTENT);
	}
}
