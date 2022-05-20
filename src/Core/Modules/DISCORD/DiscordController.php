<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use function Safe\preg_split;
use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	Http,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	SettingManager,
};

/**
 * @author Nadyita (RK5)
 */
#[NCA\Instance]
class DiscordController extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** The Discord bot token to send messages with */
	#[NCA\DefineSetting(
		type: 'discord_bot_token',
		options: ["off"],
		accessLevel: 'superadmin'
	)]
	public string $discordBotToken = "off";

	/** Discord channel to send notifications to */
	#[NCA\DefineSetting(type: "discord_channel", accessLevel: "admin")]
	public string $discordNotifyChannel = "off";

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
			"GFX_GUI_WINDOW_QUESTIONMARK" => "❓",
		];
		$text = preg_replace_callback(
			"/<img src=['\"]?tdb:\/\/id:([A-Z0-9_]+)['\"]?>/",
			function(array $matches) use ($mapping): string {
				return ($mapping[$matches[1]] ?? $matches[1]) . " ";
			},
			$text
		);
		return $text;
	}

	protected function factionColorsToEmojis(string $text): string {
		$mapping = [
			"neutral" => "▪️",
			"clan" => "🔸",
			"omni" => "🔹",
		];
		$text = preg_replace_callback(
			"/<(neutral|clan|omni)>(.+?)<end>/s",
			function(array $matches) use ($mapping): string {
				return $mapping[$matches[1]] . $matches[2];
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
		$text = $this->factionColorsToEmojis($text);
		$text = preg_replace('/([~`_*])/s', "\\\\$1", $text);
		$text = preg_replace('/((?:\d{4}-\d{2}-\d{2} )?\d+(?::\d+)+)/s', "`$1`", $text);
		$text = preg_replace('/(\d{4}-\d{2}-\d{2})(\s*(?:\||<highlight>\|<end>))/s', "`$1`$2", $text);
		$text = preg_replace('/((?:\||<highlight>\|<end>)\s*)(<black>0+<end>)?(\d+)(\s*(?:\||<highlight>\|<end>))/s', "$1$2`$3`$4", $text);
		$text = preg_replace('/QL(\s*)(<black>0+<end>)?(\d+)/s', "QL$1$2`$3`", $text);
		$text = preg_replace('/(\s*)(<black>0+<end>)?(\d+)(\s*(?:\||<highlight>\|<end>))/s', "$1$2`$3`$4", $text);
		$text = preg_replace('/(\d+\.\d+)(°|mm|%|\s*\|)/s', "`$1`$2", $text);
		$text = preg_replace('/<(highlight|black|white|yellow|blue|green|red|on|off|orange|grey|cyan|violet|neutral|omni|clan|unknown|font [^>]*)><end>/s', '', $text);
		$text = preg_replace('/<highlight>(.*?)<end>/s', '**$1**', $text);
		$text = preg_replace('/(\s|\*)-(>|&gt;)(\s|\*)/s', '$1↦$3', $text);
		$text = str_replace("<myname>", $this->chatBot->char->name, $text);
		$text = str_replace("<myguild>", $this->config->orgName, $text);
		$text = str_replace("<symbol>", $this->settingManager->getString("symbol")??"!", $text);
		$text = str_replace("<br>", "\n", $text);
		$text = str_replace("<tab>", "_ _  ", $text);
		$text = preg_replace("/^    /m", "_ _  ", $text);
		$text = preg_replace("/\n<img src=['\"]?rdb:\/\/[^>]+?['\"]?>\n/s", "\n", $text);
		$text = preg_replace_callback(
			"/(?:<font[^>]*#000000[^>]*>|<black>)(.+?)(?:<end>|<\/font>)/s",
			function(array $matches): string {
				if (preg_match("/^0+$/", $matches[1])) {
					return "_ _" . str_repeat(" ", strlen($matches[1]));
					// return "_ _" . str_repeat(" ", strlen($matches[1]));
				}
				return "_ _" . str_repeat(" ", strlen(str_replace("\\", "", $matches[1])));
			},
			$text
		);
		$text = preg_replace('/(<end>|<\/font>)<(white|yellow|blue|green|red|orange|grey|cyan|violet|neutral|omni|clan|unknown|font [^>]+)>/s', '', $text);
		$text = preg_replace('/<(white|yellow|blue|green|red|orange|grey|cyan|violet|neutral|omni|clan|unknown)>(.*?)<end>/s', '*$2*', $text);
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
		$linksReplaced2 = 0;
		$text = preg_replace(
			"|<a [^>]*?href=['\"]itemid://53019/(\d+)['\"]>(.+?)</a>|s",
			"[$2](https://aoitems.com/item/$1)",
			$text,
			-1,
			$linksReplaced2
		);
		$linksReplaced = ($linksReplaced??0) + ($linksReplaced2??0);

		$embeds = [];
		$text = preg_replace_callback(
			'|<a href="text://(.+?)">(.+?)</a>|s',
			function (array $matches) use (&$embeds, $text): string {
				$embeds []= $this->parsePopupToEmbed($matches);
				return ($text === $matches[0]) ? "" : "__**" . $matches[2] . "**__";
			},
			$text
		);

		$text = strip_tags($text);
		$text = str_replace(["&lt;", "&gt;"], ["<", ">"], $text);
		if (!count($embeds) && $linksReplaced !== 0) {
			$embed = new DiscordEmbed();
			$embed->description = $text;
			$text = "";
			$embeds []= $embed;
		}
		$msg = new DiscordMessageOut($text);
		if (count($embeds)) {
			$msg->embeds = $embeds;
		}
		return $msg;
	}

	/**
	 * Send a message to the configured Discord channel (if configured)
	 * @param string|string[] $text
	 */
	public function sendDiscord(string|array $text, bool $allowGroupMentions=false): void {
		if ($this->discordBotToken === "" || $this->discordBotToken === 'off') {
			return;
		}
		if ($this->discordNotifyChannel === 'off') {
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
				$message->allowed_mentions->parse []= ["roles"];
				$message->allowed_mentions->parse []= ["here"];
				$message->allowed_mentions->parse []= ["everyone"];
			}
			$this->discordAPIClient->sendToChannel(
				$this->discordNotifyChannel,
				$message->toJSON()
			);
		}
	}

	/** @param string[] $matches */
	protected function parsePopupToEmbed(array $matches): DiscordEmbed {
		$fix = function(string $s): string {
			return htmlspecialchars_decode(strip_tags($s), ENT_QUOTES|ENT_HTML401);
		};
		$embed = new DiscordEmbed();
		$embed->title = $matches[2];
		if (preg_match("/^<font.*?><header>(.+?)<end>\n/s", $matches[1], $headerMatch)) {
			$embed->title = $fix($headerMatch[1]);
			$matches[1] = preg_replace("/^(<font.*?>)<header>(.+?)<end>\n/s", "$1", $matches[1]);
		}
		$matches[1] = preg_replace('/<font+?>(.*?)<\/font>/s', "*$1*", $matches[1]);
		$fields = preg_split("/\n(<font color=#FCA712>.+?|<header2>.+?)\n/", $matches[1], -1, PREG_SPLIT_DELIM_CAPTURE);
		for ($i = 1; $i < count($fields); $i+=2) {
			$embed->fields ??= [];
			$field = new DiscordEmbedField();
			$field->name = $fix($fields[$i]);
			$field->value = $fix($fields[$i+1]);

			$field->name = preg_replace("/\[(.+?)\]\(.*?\)/", "$1", $field->name);
			if (strlen($field->value) > 1024) {
				$parts = preg_split("/(.{1,1024})\n/s", $field->value, -1, PREG_SPLIT_DELIM_CAPTURE);
				$field->value = $parts[1];
				$embed->fields []= $field;
				$field = clone $field;
				$field->name .= " (continued)";
				for ($j = 3; $j < count($parts); $j += 2) {
					$field = clone $field;
					$field->value = $parts[$j];
					$embed->fields []= $field;
				}
			} else {
				$embed->fields []= $field;
			}
		}
		$embed->description = $fix($fields[0]);
		if (strlen($embed->description) > 4096) {
			$embed->description = substr($embed->description, 0, 4095) . "…";
		}
		return $embed;
	}
}
