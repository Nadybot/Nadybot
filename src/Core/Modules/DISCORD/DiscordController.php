<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Exception;
use Nadybot\Core\{
	Http,
	LoggerWrapper,
	Nadybot,
	SettingManager,
};

/**
 * @author Nadyita (RK5)
 *
 * @Instance
 */
class DiscordController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public DiscordAPIClient $discordAPIClient;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			'discord_bot_token',
			'The Discord bot token to send messages with',
			'edit',
			'text',
			'',
			'',
			'',
			'admin'
		);
		$this->settingManager->registerChangeListener(
			"discord_bot_token",
			[$this, "validateBotToken"]
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_notify_channel",
			"Discord channel to send notifications to",
			"edit",
			"discord_channel",
			"off"
		);
	}

	/**
	 * Check if the given $newValue is a valid Discord Bot Token
	 *
	 * @throws Exception if new value is not a valid Discord Bot Token
	 */
	public function validateBotToken(string $settingName, string $oldValue, string $newValue): void {
		if ($newValue === '') {
			return;
		}
		$response = $this->http
			->get(DiscordAPIClient::DISCORD_API . "/users/@me")
			->withHeader('Authorization', 'Bot ' . $newValue)
			->withTimeout(10)
			->waitAndReturnResponse();
		if ($response->headers["status-code"] !== "200") {
			throw new Exception("<highlight>{$newValue}<end> is not a valid Discord Bot Token");
		}
	}

	/**
	 * Reformat a Nadybot message for sending to Discord
	 */
	public function formatMessage(string $text): DiscordMessageOut {
		$text = preg_replace('/([~`_*])/', "\\\\$1", $text);
		$text = preg_replace('/<highlight>(.*?)<end>/', '**$1**', $text);
		$text = str_replace("<myname>", $this->chatBot->vars["name"], $text);
		$text = str_replace("<myguild>", $this->chatBot->vars["my_guild"], $text);
		$text = str_replace("<symbol>", $this->settingManager->get("symbol"), $text);
		$text = str_replace("<br>", "\n", $text);
		$text = str_replace("<tab>", "\t", $text);
		$text = preg_replace('/<[a-z]+?>(.*?)<end>/s', '*$1*', $text);

		$text = preg_replace("|<a [^>]*?href='user://(.+?)'>(.+?)</a>|s", '$1', $text);
		$text = preg_replace("|<a [^>]*?href='chatcmd:///start (.+?)'>(.+?)</a>|s", '[$2]($1)', $text);
		$text = preg_replace("|<a [^>]*?href='chatcmd://(.+?)'>(.+?)</a>|s", '$2', $text);
		$linksReplaced = 0;
		$text = preg_replace(
			"|<a [^>]*?href=['\"]itemref://(\d+)/(\d+)/(\d+)['\"]>(.+?)</a>|s",
			"[$4](https://aoitems.com/item/$1/$3)",
			$text,
			-1,
			$linksReplaced
		);

		$embeds = [];
		$text = preg_replace_callback(
			'|<a href="text://(.+?)">(.+?)</a>|s',
			function (array $matches) use (&$embeds): string {
				$embeds []= $this->parsePopupToEmbed($matches);
				return "";
			},
			$text
		);

		$text = strip_tags($text);
		$text = str_replace(["&lt;", "&gt;"], ["<", ">"], $text);
		if (!count($embeds) && $linksReplaced > 0) {
			$embed = new DiscordEmbed();
			$embed->description = $text;
			$text = "";
			$embeds []= $embed;
		}
		$msg = new DiscordMessageOut($text);
		if (count($embeds)) {
			$msg->embed = $embeds[0];
		}
		return $msg;
	}

	/**
	 * Send a message to the configured Discord channel (if configured)
	 */
	public function sendDiscord($text): void {
		$discordBotToken = $this->settingManager->getString('discord_bot_token');
		if ($discordBotToken === "") {
			return;
		}
		$discordChannel = $this->settingManager->getString('discord_notify_channel');
		if ($discordChannel === 'off') {
			return;
		}
		if (!is_array($text)) {
			$text = [$text];
		}
		foreach ($text as $page) {
			$message = $this->formatMessage($page);
			$this->discordAPIClient->sendToChannel($discordChannel, $message->toJSON());
		}
	}

	protected function parsePopupToEmbed(array $matches): DiscordEmbed {
		$embed = new DiscordEmbed();
		$embed->title = $matches[2];
		if (preg_match("/^<font.*?>\*(.+?)\*\n/s", $matches[1], $headerMatch)) {
			$embed->title = $headerMatch[1];
			$matches[1] = preg_replace("/^(<font.*?>)\*(.+?)\*\n/s", "$1", $matches[1]);
		}
		$matches[1] = preg_replace('/<font+?>(.*?)<\/font>/s', "*$1*", $matches[1]);
		$embed->description = htmlspecialchars_decode(strip_tags($matches[1], ENT_QUOTES|ENT_HTML401));
		if (strlen($embed->description) > 2048) {
			$embed->description = substr($embed->description, 0, 2047) . "…";
		}
		return $embed;
	}
}
