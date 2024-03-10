<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use function Safe\preg_replace;
use Nadybot\Core\Modules\{
	CONFIG\SettingOption,
};

use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
};

/**
 * @author Nadyite (RK5)
 */
#[NCA\Instance]
class DiscordRelayController extends ModuleInstance {
	/** Minimum ranks allowed to use @here and @everyone */
	#[NCA\Setting\Rank]
	public string $discordRelayMentionRank = "mod";
	#[NCA\Inject]
	private DiscordGatewayController $discordGatewayController;

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
					// @phpstan-ignore-next-line
					if (!isset($char)) {
						return $matches[1];
					}
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
