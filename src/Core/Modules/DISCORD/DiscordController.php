<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use function Amp\async;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	ModuleInstance,
	Safe,
	SettingManager,
	Text,
	Types\AccessLevel,
};
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\DiscordGatewayController;

/**
 * @author Nadyita (RK5)
 */
#[NCA\Instance]
class DiscordController extends ModuleInstance {
	/** The Discord bot token to send messages with */
	#[NCA\DefineSetting(
		type: 'discord_bot_token',
		options: ['off'],
		accessLevel: AccessLevel::Superadmin,
		confidential: true,
	)]
	public string $discordBotToken = 'off';

	/** Discord channel to send notifications to */
	#[NCA\DefineSetting(type: 'discord_channel', accessLevel: AccessLevel::Admin)]
	public string $discordNotifyChannel = 'off';

	/** Use custom Emojis */
	#[NCA\Setting\Boolean]
	public bool $discordCustomEmojis = true;

	/** Which website to use to show links to items */
	#[NCA\Setting\Template(
		exampleValues: [
			'id' => 12_345,
			'ql' => 200,
		],
		options: [
			'aoitems' => 'https://aoitems.com/item/{id}{?ql:/{ql}}',
			'auno' => 'https://auno.org/ao/db.php?id={id}{?ql:&ql={ql}}',
			'aogalaxy' => 'https://www.aogalaxy.com/_items/item.php?aoid={id}{?ql:&ql={ql}}',
		]
	)]
	public string $itemWebsite = 'https://auno.org/ao/db.php?id={id}{?ql:&ql={ql}}';

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	private DiscordGatewayController $discordGatewayController;

	/** Reformat a Nadybot message for sending to Discord */
	public function formatMessage(string $text, ?Guild $guild=null): DiscordMessageOut {
		$text = $this->aoIconsToEmojis($guild, $text);
		$text = $this->factionColorsToEmojis($guild, $text);
		$text = Safe::pregReplace('/([~`_*])/s', '\\\$1', $text);
		$text = Safe::pregReplace('/((?:\d{4}-\d{2}-\d{2} )?\d+(?::\d+)+)/s', '`$1`', $text);
		$text = Safe::pregReplace('/(\d{4}-\d{2}-\d{2})(\s*(?:\||<highlight>\|<end>))/s', '`$1`$2', $text);
		$text = Safe::pregReplace('/((?:\||<highlight>\|<end>)\s*)(<black>0+<end>)?(\d+)(\s*(?:\||<highlight>\|<end>))/s', '$1$2`$3`$4', $text);
		$text = Safe::pregReplace('/QL(\s*)(<black>0+<end>)?(\d+)/s', 'QL$1$2`$3`', $text);
		$text = Safe::pregReplace('/(\s*)(<black>0+<end>)?(\d+)(\s*(?:\||<highlight>\|<end>))/s', '$1$2`$3`$4', $text);
		$text = Safe::pregReplace('/(\d+\.\d+)(°|mm|%|\s*\|)/s', '`$1`$2', $text);
		$text = Safe::pregReplace('/<(highlight|black|white|yellow|blue|green|red|on|off|orange|grey|cyan|violet|neutral|omni|clan|unknown|font [^>]*)><end>/s', '', $text);
		$text = Safe::pregReplace('/<highlight>(.*?)<end>/s', '**$1**', $text);
		$text = Safe::pregReplace('/(\s|\*)-(>|&gt;)(\s|\*)/s', '$1↦$3', $text);
		$text = str_replace('<myname>', $this->config->main->character, $text);
		$text = str_replace('<myguild>', $this->config->general->orgName, $text);
		$text = str_replace('<symbol>', $this->settingManager->getString('symbol')??'!', $text);
		$text = str_replace('<br>', "\n", $text);
		$text = str_replace('<tab>', '_ _  ', $text);
		$text = Safe::pregReplace('/^    /m', '_ _  ', $text);
		$text = Safe::pregReplace("/\n<img src=['\"]?rdb:\/\/[^>]+?['\"]?>\n/s", "\n", $text);
		$text = Safe::pregReplaceCallback(
			"/(?:<font[^>]*#000000[^>]*>|<black>)(.+?)(?:<end>|<\/font>)/s",
			/** @param string[] $matches */
			static function (array $matches): string {
				if (Safe::pregMatches('/^0+$/', $matches[1])) {
					return '_ _' . str_repeat(' ', strlen($matches[1]));
				}
				return '_ _' . str_repeat(' ', strlen(str_replace('\\', '', $matches[1])));
			},
			$text
		);
		$text = Safe::pregReplace('/(<end>|<\/font>)<(white|yellow|blue|green|red|orange|grey|cyan|violet|neutral|omni|clan|unknown|font [^>]+)>/s', '', $text);
		$text = Safe::pregReplace('/<(white|yellow|blue|green|red|orange|grey|cyan|violet|neutral|omni|clan|unknown)>(.*?)<end>/s', '*$2*', $text);
		$text = Safe::pregReplaceCallback(
			'/\*?(-{5,}+)\*?/s',
			static function (array $match): string {
				return str_repeat(' ', strlen($match[1]));
			},
			$text
		);
		$text = Safe::pregReplace("|<a [^>]*?href='user://(.+?)'>(.+?)</a>|s", '$1', $text);
		$text = Safe::pregReplace("|<a [^>]*?href='chatcmd:///start (.+?)'>(.+?)</a>|s", '[$2]($1)', $text);
		$text = Safe::pregReplace("|<a [^>]*?href='chatcmd://(.+?)'>(.+?)</a>|s", '$2', $text);
		$linksReplaced = 0;
		$text = Safe::pregReplaceCallback(
			"|<a [^>]*?href=['\"]?itemref://(\d+)/(\d+)/(\d+)['\"]?>(.+?)</a>|s",
			function (array $match): string {
				$tokens = [
					'id' => $match[1],
					'ql' => $match[3],
				];
				$url = Text::renderPlaceholders($this->itemWebsite, $tokens);
				return "[{$match[4]}]({$url})";
			},
			$text,
			-1,
			$linksReplaced
		);

		/** @var int $linksReplaced */
		$linksReplaced2 = 0;
		$text = Safe::pregReplaceCallback(
			"|<a [^>]*?href=['\"]itemid://53019/(\d+)['\"]>(.+?)</a>|s",
			function (array $match): string {
				$tokens = [
					'id' => $match[1],
				];
				$url = Text::renderPlaceholders($this->itemWebsite, $tokens);
				return "[{$match[2]}]({$url})";
			},
			$text,
			-1,
			$linksReplaced2
		);

		/** @var int $linksReplaced2 */
		$linksReplaced = $linksReplaced + $linksReplaced2;

		$embeds = [];
		$text = Safe::pregReplaceCallback(
			'|<a href="text://(.+?)">(.+?)</a>|s',
			function (array $matches) use (&$embeds, $text): string {
				$embeds []= $this->parsePopupToEmbed($matches);
				return ($text === $matches[0]) ? '' : '__**' . $matches[2] . '**__';
			},
			$text
		);

		$text = strip_tags($text);
		$text = str_replace(['&lt;', '&gt;'], ['<', '>'], $text);
		if (!count($embeds) && $linksReplaced !== 0) {
			$embed = new DiscordEmbed();
			$embed->description = $text;
			$text = '';
			$embeds []= $embed;
		}
		if (str_ends_with($text, '     ')) {
			$text .= "\n_ _";
		}
		$msg = new DiscordMessageOut($text);
		if (count($embeds)) {
			$msg->embeds = $embeds;
		}
		return $msg;
	}

	/**
	 * Send a message to the configured Discord channel (if configured)
	 *
	 * @param string|list<string> $text
	 */
	public function sendDiscord(string|array $text, bool $allowGroupMentions=false): void {
		if ($this->discordBotToken === '' || $this->discordBotToken === 'off') {
			return;
		}
		if ($this->discordNotifyChannel === 'off') {
			return;
		}
		if (!is_array($text)) {
			$text = [$text];
		}
		$guild = $this->discordGatewayController->getChannelGuild($this->discordNotifyChannel);
		foreach ($text as $page) {
			$message = $this->formatMessage($page, $guild);
			$message->allowed_mentions = new DiscordAllowedMentions(
				parse: [DiscordAllowedMentionType::Users],
			);
			if (!$allowGroupMentions) {
				$message->allowed_mentions->parse []= DiscordAllowedMentionType::Roles;
				$message->allowed_mentions->parse []= DiscordAllowedMentionType::Here;
				$message->allowed_mentions->parse []= DiscordAllowedMentionType::Everyone;
			}
			foreach ($message->split() as $msgPart) {
				// @todo Bad design, possibly distorting order, because unshifting
				async(
					$this->discordAPIClient->sendToChannel(...),
					$this->discordNotifyChannel,
					$msgPart->toJSON()
				)->ignore();
			}
		}
	}

	protected function aoIconsToEmojis(?Guild $guild, string $text): string {
		$mapping = [
			'GFX_GUI_ICON_PROFESSION_1' => $this->getEmoji($guild, 'soldier') ?? '🔫',
			'GFX_GUI_ICON_PROFESSION_2' => $this->getEmoji($guild, 'martialartist') ?? '🥋',
			'GFX_GUI_ICON_PROFESSION_3' => $this->getEmoji($guild, 'engineer') ?? '⚙️',
			'GFX_GUI_ICON_PROFESSION_4' => $this->getEmoji($guild, 'fixer') ?? '🔓',
			'GFX_GUI_ICON_PROFESSION_5' => $this->getEmoji($guild, 'agent') ?? '🕵️',
			'GFX_GUI_ICON_PROFESSION_6' => $this->getEmoji($guild, 'adventurer') ?? '🧭',
			'GFX_GUI_ICON_PROFESSION_7' => $this->getEmoji($guild, 'trader') ?? '💵',
			'GFX_GUI_ICON_PROFESSION_8' => $this->getEmoji($guild, 'bureaucrat') ?? '📎',
			'GFX_GUI_ICON_PROFESSION_9' => $this->getEmoji($guild, 'enforcer') ?? '🗣️',
			'GFX_GUI_ICON_PROFESSION_10' => $this->getEmoji($guild, 'doctor') ?? '🩹',
			'GFX_GUI_ICON_PROFESSION_11' => $this->getEmoji($guild, 'nanotechnician') ?? '💥',
			'GFX_GUI_ICON_PROFESSION_12' => $this->getEmoji($guild, 'metaphysicist') ?? '⚱️',
			'GFX_GUI_ICON_PROFESSION_14' => $this->getEmoji($guild, 'keeper') ?? '🛡️',
			'GFX_GUI_ICON_PROFESSION_15' => $this->getEmoji($guild, 'shade') ?? '🗡️',
			'GFX_GUI_WINDOW_QUESTIONMARK' => '❓',
		];
		$text = Safe::pregReplaceCallback(
			"/<img src=['\"]?tdb:\/\/id:([A-Z0-9_]+)['\"]?>/",
			static function (array $matches) use ($mapping): string {
				return ($mapping[$matches[1]] ?? $matches[1]) . ' ';
			},
			$text
		);
		return $text;
	}

	protected function factionColorsToEmojis(?Guild $guild, string $text): string {
		$mapping = [
			'neutral' => $this->getEmoji($guild, 'neutral') ?? '▪️',
			'clan' => $this->getEmoji($guild, 'clan') ?? '🔸',
			'omni' => $this->getEmoji($guild, 'omni') ?? '🔹',
			'on' => $this->getEmoji($guild, 'on') ?? '🟢 ',
			'off' => $this->getEmoji($guild, 'off') ?? '🔴 ',
		];
		$text = Safe::pregReplaceCallback(
			'/<(neutral|clan|omni|on|off)>(.+?)<end>/s',
			static function (array $matches) use ($mapping): string {
				return $mapping[$matches[1]] . $matches[2];
			},
			$text
		);
		return $text;
	}

	/** @param array<array-key,string> $matches */
	protected function parsePopupToEmbed(array $matches): DiscordEmbed {
		$fix = static function (string $s): string {
			return htmlspecialchars_decode(strip_tags($s), \ENT_QUOTES|\ENT_HTML401);
		};
		$embed = new DiscordEmbed();
		$embed->title = $matches[2];
		if (count($headerMatch = Safe::pregMatch("/^<font.*?><header>(.+?)<end>\n/s", $matches[1]))) {
			$embed->title = $fix($headerMatch[1]);
			$matches[1] = Safe::pregReplace("/^(<font.*?>)<header>(.+?)<end>\n/s", '$1', $matches[1]);
		}
		$matches[1] = Safe::pregReplace('/<font+?>(.*?)<\/font>/s', '*$1*', $matches[1]);
		$fields = Safe::pregSplit("/\n(<font color=#FCA712>.+?\n|<header2>[^>]+?<end>|<header2>.+?\n)/", $matches[1], -1, true);
		for ($i = 1; $i < count($fields); $i+=2) {
			$embed->fields ??= [];
			$field = new DiscordEmbedField(
				name: $fix($fields[$i]),
				value: $fix($fields[$i+1]),
			);

			$field->name = Safe::pregReplace("/\[(.+?)\]\(.*?\)/", '$1', $field->name);
			if (strlen($field->value) > 1_024) {
				$parts = Safe::pregSplit("/(.{1,1024})\n/s", $field->value, -1, true);
				$field->value = $parts[1];
				$embed->fields []= $field;
				$field = clone $field;
				$field->name .= ' (continued)';
				for ($j = 3; $j < count($parts); $j += 2) {
					$field = clone $field;
					$field->value = $parts[$j];
					$embed->fields []= $field;
				}
			} else {
				if ($field->value === '') {
					$field->value = '_ _';
				}
				$embed->fields []= $field;
			}
		}
		$embed->description = $fix($fields[0]);
		if (strlen($embed->description) > 4_096) {
			$embed->description = substr($embed->description, 0, 4_095) . '…';
		}
		return $embed;
	}

	private function getEmoji(?Guild $guild, string $name): ?string {
		if (!isset($guild) || !$this->discordCustomEmojis) {
			return null;
		}
		foreach ($guild->emojis as $emoji) {
			if ($emoji->name === $name) {
				if ($emoji->animated) {
					return "&lt;a:{$name}:{$emoji->id}&gt;";
				}
				return "&lt;:{$name}:{$emoji->id}&gt;";
			}
		}
		return null;
	}
}
