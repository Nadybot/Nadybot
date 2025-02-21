<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Modules\{
	CONFIG\SettingOption,
};

use Nadybot\Core\Types\AccessLevel;
use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
	Safe,
};

/**
 * @author Nadyite (RK5)
 */
#[NCA\Instance]
class DiscordRelayController extends ModuleInstance {
	/** Minimum ranks allowed to use @here and @everyone */
	#[NCA\Setting\Rank]
	public AccessLevel $discordRelayMentionRank = AccessLevel::Mod;

	#[NCA\Inject]
	private DiscordGatewayController $discordGatewayController;

	/**
	 * Gives a list of all channels we have access to
	 *
	 * @return list<SettingOption>
	 */
	public function getChannelOptionList(): array {
		$guilds = $this->discordGatewayController->getGuilds();
		if (!count($guilds)) {
			return [];
		}

		/** @var list<SettingOption> */
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
					$result []= new SettingOption(
						value: $subchannel->id,
						name: "{$guild->name} > {$channel->name} > #{$subchannel->name}",
					);
				}
			}
		}
		return $result;
	}

	public static function formatMessage(string $text): string {
		$smileyMapping = [
			'☺️' => ':-3',
			'🙂' => ':-)',
			'😊' => ':o)',
			'😀' => ':-D',
			'😁' => '^_^',
			'😂' => ":'-)",
			'😃' => ':-)',
			'😄' => 'xD',
			'😆' => 'xDD',
			'😍' => '(*_*)',
			'☹️' => ':-<',
			'🙁' => ':o(',
			'😠' => '>:-[',
			'😡' => '>:-@',
			'😞' => ':-c',
			'😟' => ':-<',
			'😣' => '(>_<)',
			'😖' => '(>_<)>',
			'😢' => ":'-(",
			'😭' => 'T_T',
			'😨' => 'D-:',
			'😧' => '>:-|',
			'😦' => 'D:<',
			'😱' => ':panic:',
			'😫' => 'v.v',
			'😩' => 'v.v',
			'😮' => ':-O',
			'😯' => ':-o',
			'😲' => '>:O',
			'😗' => ':-*',
			'😙' => ':-*',
			'😚' => ':-*',
			'😘' => ':-*',
			'😉' => ';-)',
			'😜' => ';-P',
			'😛' => ':-P',
			'😝' => ':‑Þ',
			'🤑' => ':-$',
			'🤔' => ':S',
			'😕' => ':-\\',
			'😐' => ':-|',
			'😑' => '-_-',
			'😳' => ':$',
			'🤐' => ':-X',
			'😶' => ':-#',
			'😇' => 'O:-)',
			'👼' => 'O:-]',
			'😈' => '>:-)',
			'😎' => 'B-)',
			'😪' => '|-O',
			'😏' => ':-J',
			'😒' => '(-.-)',
			'😵' => '%-O',
			'🤕' => '%-|',
			'🤒' => ':-###',
			'😷' => ':-#',
			'🤢' => ':-X',
			'🤨' => 'o.O',
			'😬' => ':E',
			'🌹' => "@}‑;‑'‑‑‑",
			'❤️' => '<3',
			'💔' => '<\\3',
			'😴' => 'zzZ',
			'🙄' => '(°_°)',
			'😅' => "^_^'",
			'🤦' => ':facepalm:',
			'🤷' => ':shrug:',
		];
		$text = Safe::pregReplace("/<:([a-z0-9]+):(\d+)>/i", ':$1:', $text);
		$text = Safe::pregReplace('/```(.+?)```/s', '$1', $text);
		$text = Safe::pregReplace('/`(.+?)`/s', '$1', $text);
		$text = htmlspecialchars($text);
		$text = Safe::pregReplace("/\*\*(.+?)\*\*/s", '<highlight>$1<end>', $text);
		$text = Safe::pregReplace("/\*(.+?)\*/s", '<i>$1</i>', $text);
		$text = Safe::pregReplace('/\\\\-/s', '-', $text);
		$text = Safe::pregReplace("/\[(.+?)\]\((.+?)\)/s", "<a href='chatcmd:///start $2'>$1</a>", $text);
		$text = str_replace(
			array_keys($smileyMapping),
			array_values($smileyMapping),
			$text
		);
		if (class_exists('IntlChar')) {
			$text = Safe::pregReplaceCallback(
				"/([\x{0450}-\x{2018}\x{2020}-\x{fffff}])/u",
				static function (array $matches): string {
					$char = \IntlChar::charName($matches[1]);
					// @phpstan-ignore-next-line
					if (!isset($char)) {
						return $matches[1];
					}
					if ($char === 'ZERO WIDTH JOINER'
						|| substr($char, 0, 19) === 'VARIATION SELECTOR-'
						|| substr($char, 0, 14) === 'EMOJI MODIFIER'
					) {
						return '';
					}
					return ":{$char}:";
				},
				$text
			);
		}
		return $text;
	}
}
