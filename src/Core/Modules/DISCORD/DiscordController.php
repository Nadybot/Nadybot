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
			'discord_bot_token',
			'off',
			'off',
			'',
			'superadmin'
		);
		$this->settingManager->add(
			$this->moduleName,
			"discord_notify_channel",
			"Discord channel to send notifications to",
			"edit",
			"discord_channel",
			"off",
			"",
			"",
			"admin"
		);
	}

	protected function aoIconsToEmojis(string $text): string {
		$mapping = [
			"GFX_GUI_ICON_PROFESSION_1" => "🔫",
			"GFX_GUI_ICON_PROFESSION_2" => "🥋",
			"GFX_GUI_ICON_PROFESSION_3" => "⚙️",
			"GFX_GUI_ICON_PROFESSION_4" => "🔓",
			"GFX_GUI_ICON_PROFESSION_5" => "🕵️",
			"GFX_GUI_ICON_PROFESSION_6" => "🧭",
			"GFX_GUI_ICON_PROFESSION_7" => "💵",
			"GFX_GUI_ICON_PROFESSION_8" => "📎",
			"GFX_GUI_ICON_PROFESSION_9" => "🗣️",
			"GFX_GUI_ICON_PROFESSION_10" => "🩹",
			"GFX_GUI_ICON_PROFESSION_11" => "💥",
			"GFX_GUI_ICON_PROFESSION_12" => "⚱️",
			"GFX_GUI_ICON_PROFESSION_14" => "🛡️",
			"GFX_GUI_ICON_PROFESSION_15" => "🗡️",
		];
		$text = preg_replace_callback(
			"/<img src=['\"]?tdb:\/\/id:([A-Z0-9_]+)['\"]?>/",
			function(array $matches) use ($mapping): string {
				return $mapping[$matches[1]] ?? $matches[1];
			},
			$text
		);
		return $text;
	}

	/**
	 * Reformat a Nadybot message for sending to Discord
	 */
	public function formatMessage(string $text): DiscordMessageOut {
		$text = $this->aoIconsToEmojis($text);
		$text = preg_replace('/([~`_*])/', "\\\\$1", $text);
		$text = preg_replace('/((?:\d{4}-\d{2}-\d{2} )?\d+(?::\d+)+)/', "`$1`", $text);
		$text = preg_replace('/<(highlight|black|white|yellow|blue|green|red|orange|grey|cyan|violet|neutral|omni|clan|unknown|font [^>]*)><end>/s', '', $text);
		$text = preg_replace('/<highlight>(.*?)<end>/', '**$1**', $text);
		$text = str_replace("<myname>", $this->chatBot->vars["name"], $text);
		$text = str_replace("<myguild>", $this->chatBot->vars["my_guild"], $text);
		$text = str_replace("<symbol>", $this->settingManager->get("symbol"), $text);
		$text = str_replace("<br>", "\n", $text);
		$text = str_replace("<tab>", "_ _  ", $text);
		$text = preg_replace("/^    /m", "_ _  ", $text);
		$text = preg_replace("/\n<img src=['\"]?rdb:\/\/.+?['\"]?>\n/s", "\n", $text);
		$text = preg_replace_callback(
			"/(?:<font[^>]*#000000[^>]*>|<black>)(0+)(?:<end>|<\/font>)/",
			function(array $matches): string {
				return str_repeat(" ", strlen($matches[1]));
			},
			$text
		);
		$text = preg_replace('/<(black|white|yellow|blue|green|red|orange|grey|cyan|violet|neutral|omni|clan|unknown)>(.*?)<end>/s', '*$2*', $text);
		$text = preg_replace("|<a [^>]*?href='user://(.+?)'>(.+?)</a>|s", '$1', $text);
		$text = preg_replace("|<a [^>]*?href='chatcmd:///start (.+?)'>(.+?)</a>|s", '[$2]($1)', $text);
		$text = preg_replace("|<a [^>]*?href='chatcmd://(.+?)'>(.+?)</a>|s", '$2', $text);
		$linksReplaced = 0;
		$text = preg_replace(
			"|<a [^>]*?href=['\"]?itemref://(\d+)/(\d+)/(\d+)['\"]?>(.+?)</a>|s",
			"[$4](https://aoitems.com/item/$1/$3)",
			$text,
			-1,
			$linksReplaced
		);
		$text = preg_replace(
			"|<a [^>]*?href=['\"]itemid://53019/(\d+)['\"]>(.+?)</a>|s",
			"[$2](https://aoitems.com/item/$1)",
			$text,
			-1,
			$linksReplaced2
		);
		$linksReplaced += $linksReplaced2;

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
	public function sendDiscord($text, $allowGroupMentions=false): void {
		$discordBotToken = $this->settingManager->getString('discord_bot_token');
		if ($discordBotToken === "" || $discordBotToken === 'off') {
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
			$message->allowed_mentions = (object)[
				"parse" => ["users"]
			];
			if (!$allowGroupMentions) {
				$message->allowed_Mentions->parse []= ["roles"];
				$message->allowed_Mentions->parse []= ["here"];
				$message->allowed_Mentions->parse []= ["everyone"];
			}
			$this->discordAPIClient->sendToChannel($discordChannel, $message->toJSON());
		}
	}

	protected function parsePopupToEmbed(array $matches): DiscordEmbed {
		$embed = new DiscordEmbed();
		$embed->title = $matches[2];
		if (preg_match("/^<font.*?><header>(.+?)<end>\n/s", $matches[1], $headerMatch)) {
			$embed->title = $headerMatch[1];
			$matches[1] = preg_replace("/^(<font.*?>)<header>(.+?)<end>\n/s", "$1", $matches[1]);
		}
		$matches[1] = preg_replace('/<font+?>(.*?)<\/font>/s', "*$1*", $matches[1]);
		$fields = preg_split("/\n(<font color=#FCA712>.+?|<header2>.+?)\n/", $matches[1], -1, PREG_SPLIT_DELIM_CAPTURE);
		$fix = function(string $s): string {
			return htmlspecialchars_decode(strip_tags($s), ENT_QUOTES|ENT_HTML401);
		};
		for ($i = 1; $i < count($fields); $i+=2) {
			$embed->fields ??= [];
			$embed->fields []= [
				"name"  => $fix($fields[$i]),
				"value" => $fix($fields[$i+1]),
			];
		}
		$embed->description = $fix($fields[0]);
		// $embed->description = htmlspecialchars_decode(strip_tags($matches[1], ENT_QUOTES|ENT_HTML401));
		if (strlen($embed->description) > 2048) {
			$embed->description = substr($embed->description, 0, 2047) . "…";
		}
		return $embed;
	}
}
