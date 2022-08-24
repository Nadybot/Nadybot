<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Modules\{
	ALTS\AltsController,
	CONFIG\ConfigController,
	CONFIG\SettingOption,
	DISCORD\DiscordAPIClient,
	DISCORD\DiscordController,
	PLAYER_LOOKUP\PlayerManager,
	PREFERENCES\Preferences,
};
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	ModuleInstance,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
};
use Nadybot\Modules\{
	GUILD_MODULE\GuildController,
	PRIVATE_CHANNEL_MODULE\PrivateChannelController,
	RELAY_MODULE\RelayController,
};

/**
 * @author Nadyite (RK5)
 */
#[NCA\Instance]
class DiscordRelayController extends ModuleInstance {
	#[NCA\Inject]
	public DiscordGatewayController $discordGatewayController;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public RelayController $relayController;

	#[NCA\Inject]
	public DiscordAPIClient $discordAPIClient;

	#[NCA\Inject]
	public DiscordController $discordController;

	#[NCA\Inject]
	public GuildController $guildController;

	#[NCA\Inject]
	public PrivateChannelController $privateChannelController;

	#[NCA\Inject]
	public ConfigController $configController;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public Preferences $preferences;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DiscordGatewayCommandHandler $discordGatewayCommandHandler;

	#[NCA\Inject]
	public Nadybot $chatBot;

	/** Minimum ranks allowed to use @here and @everyone */
	#[NCA\Setting\Rank]
	public string $discordRelayMentionRank = "mod";

	/**
	 * Gives a list of all channels we have access to
	 *
	 * @return SettingOption[]
	 */
	public function getChannelOptionList(): array {
		$guilds = $this->discordGatewayController->getGuilds();
		if (empty($guilds)) {
			return [];
		}

		/** @var SettingOption[] */
		$result = [];
		foreach ($guilds as $guildId => $guild) {
			foreach ($guild->channels as $channel) {
				if ($channel->type !== $channel::GUILD_CATEGORY) {
					continue;
				}
				foreach ($guild->channels as $subchannel) {
					if (($subchannel->parent_id??null) !== $channel->id) {
						continue;
					}
					if ($subchannel->type !== $subchannel::GUILD_TEXT) {
						continue;
					}
					$option = new SettingOption();
					$option->value = $subchannel->id;
					$option->name = "{$guild->name} > {$channel->name} > #{$subchannel->name}";
					$result []= $option;
				}
			}
		}
		return $result;
	}

	public static function formatMessage(string $text): string {
		$smileyMapping = [
			"☺️" => ":-3",
			"🙂" => ":-)",
			"😊" => ":o)",
			"😀" => ":-D",
			"😁" => "^_^",
			"😂" => ":'-)",
			"😃" => ":-)",
			"😄" => "xD",
			"😆" => "xDD",
			"😍" => "(*_*)",
			"☹️" => ":-<",
			"🙁" => ":o(",
			"😠" => ">:-[",
			"😡" => ">:-@",
			"😞" => ":-c",
			"😟" => ":-<",
			"😣" => "(>_<)",
			"😖" => "(>_<)>",
			"😢" => ":'-(",
			"😭" => "T_T",
			"😨" => "D-:",
			"😧" => ">:-|",
			"😦" => "D:<",
			"😱" => ":panic:",
			"😫" => "v.v",
			"😩" => "v.v",
			"😮" => ":-O",
			"😯" => ":-o",
			"😲" => ">:O",
			"😗" => ":-*",
			"😙" => ":-*",
			"😚" => ":-*",
			"😘" => ":-*",
			"😉" => ";-)",
			"😜" => ";-P",
			"😛" => ":-P",
			"😝" => ":‑Þ",
			"🤑" => ":-$",
			"🤔" => ":S",
			"😕" => ":-\\",
			"😐" => ":-|",
			"😑" => "-_-",
			"😳" => ":$",
			"🤐" => ":-X",
			"😶" => ":-#",
			"😇" => "O:-)",
			"👼" => "O:-]",
			"😈" => ">:-)",
			"😎" => "B-)",
			"😪" => "|-O",
			"😏" => ":-J",
			"😒" => "(-.-)",
			"😵" => "%-O",
			"🤕" => "%-|",
			"🤒" => ":-###",
			"😷" => ":-#",
			"🤢" => ":-X",
			"🤨" => "o.O",
			"😬" => ":E",
			"🌹" => "@}‑;‑'‑‑‑",
			"❤️" => "<3",
			"💔" => "<\\3",
			"😴" => "zzZ",
			"🙄" => "(°_°)",
			"😅" => "^_^'",
			"🤦" => ":facepalm:",
			"🤷" => ":shrug:",
		];
		$text = preg_replace("/<:([a-z0-9]+):(\d+)>/i", ":$1:", $text);
		$text = preg_replace("/```(.+?)```/s", "$1", $text);
		$text = preg_replace("/`(.+?)`/s", "$1", $text);
		$text = htmlspecialchars($text);
		$text = preg_replace("/\*\*(.+?)\*\*/s", "<highlight>$1<end>", $text);
		$text = preg_replace("/\*(.+?)\*/s", "<i>$1</i>", $text);
		$text = preg_replace("/\\\\-/s", "-", $text);
		$text = preg_replace("/\[(.+?)\]\((.+?)\)/s", "<a href='chatcmd:///start $2'>$1</a>", $text);
		$text = str_replace(
			array_keys($smileyMapping),
			array_values($smileyMapping),
			$text
		);
		if (class_exists("IntlChar")) {
			$text = preg_replace_callback(
				"/([\x{0450}-\x{2018}\x{2020}-\x{fffff}])/u",
				function (array $matches): string {
					$char = \IntlChar::charName($matches[1]);
					if ($char === "ZERO WIDTH JOINER"
						|| substr($char, 0, 19) === "VARIATION SELECTOR-"
						|| substr($char, 0, 14) === "EMOJI MODIFIER"
					) {
						return "";
					}
					return ":{$char}:";
				},
				$text
			);
		}
		return $text;
	}
}
