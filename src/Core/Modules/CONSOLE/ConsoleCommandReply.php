<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONSOLE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\Nadybot;

class ConsoleCommandReply implements CommandReply {
	private Nadybot $chatBot;

	public function __construct(Nadybot $chatBot) {
		$this->chatBot = $chatBot;
	}

	public function reply($msg): void {
		foreach ((array)$msg as $text) {
			$text = $this->formatMsg($text);
			echo("{$this->chatBot->vars["name"]}: {$text}\n");
		}
	}

	public function formatMsg(string $message) {
		$array = [
			"<myname>" => $this->chatBot->vars["name"],
			"<myguild>" => $this->chatBot->vars["my_guild"],
			"<tab>" => "    ",
			"<symbol>" => "",
			"<u>" => "\e[4m",
			"</u>" => "\e[24m",
			"<i>" => "\e[3m",
			"</i>" => "\e[23m",
			"<br>" => "\n",
			"<br/>" => "\n",
			"<br />" => "\n"
		];

		$message = preg_replace_callback(
			"/<black>(.*?)<end>/",
			function(array $matches): string {
				if (function_exists('mb_strlen')) {
					return str_repeat(" ", mb_strlen($matches[1]));
				}
				return str_repeat(" ", strlen($matches[1]));
			},
			$message
		);
		$message = str_ireplace(array_keys($array), array_values($array), $message);
		$message = preg_replace("/<a\s+href=['\"]?skillid:\/\/\d+['\"]?>(.*?)<\/a>/s", "[skill:<link>$1</link>]", $message);
		$message = preg_replace("/<a\s+href=['\"]chatcmd:\/\/\/(.*?)['\"]>(.*?)<\/a>/s", "<link>$2</link>", $message);
		$message = preg_replace("/<a\s+href=['\"]?itemref:\/\/\d+\/\d+\/\d+['\"]?>(.*?)<\/a>/s", "[item:<link>$1</link>]", $message);
		$message = preg_replace("/<a\s+href=['\"]?itemid:\/\/53019\/\d+['\"]?>(.*?)<\/a>/s", "[nano:<link>$1</link>]", $message);
		$message = preg_replace("/\n<img\s+src=['\"]?tdb:\/\/id:[A-Z0-9_]+['\"]?>\n/s", "\n", $message);
		$message = preg_replace("/\n<img\s+src=['\"]?rdb:\/\/\d+['\"]?>\n/s", "\n", $message);
		$message = preg_replace("/<img\s+src=['\"]?tdb:\/\/id:[A-Z0-9_]+['\"]?>/s", "", $message);
		$message = preg_replace("/<img\s+src=['\"]?rdb:\/\/\d+['\"]?>/s", "", $message);
		$message = preg_replace("/\n\[item:<link><\/link>]\n/s", "\n", $message);
		$parts = [];
		$message = html_entity_decode(
			preg_replace_callback(
				"/<a href=\"text:\/\/(.+?)\">(.*?)<\/a>/s",
				function (array $matches) use (&$parts): string {
					$parts[] = $this->handleColors(html_entity_decode($matches[1]), true);
					return $this->handleColors("<link>{$matches[2]}</link>", false);
				},
				$message
			)
		);
		$message = str_replace("\n", "\n ", $this->handleColors($message, true));
		if (count($parts)) {
			$message .= "\n\n" . join("\n" . str_repeat("-", 75) . "\n", $parts);
		}

		return $message;
	}

	protected function parseBasicAnsi(string $text): string {
		$array = [
			"<header>" => "\e[1;4m",
			"<header2>" => "\e[4m",
			"<highlight>" => "\e[1m",
			"<link>" => "\e[4m",
			"</link>" => "\e[24m",
			"<black>" => "",
			"<white>" => "",
			"<yellow>" => "",
			"<blue>" => "",
			"<green>" => "",
			"<red>" => "",
			"<orange>" => "",
			"<grey>" => "",
			"<cyan>" => "",
			"<violet>" => "",

			"<neutral>" => "",
			"<omni>" => "",
			"<clan>" => "",
			"<unknown>" => "",
			"<end>" => "\e[22;24m",
		];
		$text = str_ireplace(array_keys($array), array_values($array), $text);
		$text = preg_replace("/<font\s+color\s*=\s*[\"']?#.{6}[\"']?>/is", "", $text);
		return $text;
	}

	protected function parseAnsiColors(string $text): string {
		$sm = $this->chatBot->settingManager;
		$array = [
			"<header>" => str_replace("'", "", $sm->get('default_header_color')),
			"<header2>" => str_replace("'", "", $sm->get('default_header2_color')),
			"<highlight>" => str_replace("'", "", $sm->get('default_highlight_color')),
			"<link>" => "\e[4m<font color=#219CFF>",
			"</link>" => "</font>\e[24m",
			"<black>" => "<font color=#000000>",
			"<white>" => "<font color=#FFFFFF>",
			"<yellow>" => "<font color=#FFFF00>",
			"<blue>" => "<font color=#8CB5FF>",
			"<green>" => "<font color=#00DE42>",
			"<red>" => "<font color=#FF0000>",
			"<orange>" => "<font color=#FCA712>",
			"<grey>" => "<font color=#C3C3C3>",
			"<cyan>" => "<font color=#00FFFF>",
			"<violet>" => "<font color=#8F00FF>",

			"<neutral>" => $sm->get('default_neut_color'),
			"<omni>" => $sm->get('default_omni_color'),
			"<clan>" => $sm->get('default_clan_color'),
			"<unknown>" => $sm->get('default_unknown_color'),

			"<end>" => "</font>",
		];
		$defaultColor = $sm->get('default_priv_color');
		$text = $defaultColor . str_ireplace(array_keys($array), array_values($array), $text);
		return $text;
	}

	public function handleColors(string $text, bool $clearEOL): string {
		$sm = $this->chatBot->settingManager;
		if (!$sm->getBool("console_color")) {
			return $this->parseBasicAnsi($text);
		}
		$text = $this->parseAnsiColors($text);
		$stack = [];
		$text = preg_replace_callback(
			"/<(\/?font.*?)>/",
			function (array $matches) use (&$stack): string {
				$matches[1] = strtolower($matches[1]);
				if (substr($matches[1], 0, 1) === "/") {
					array_pop($stack);
					$currentTag = $stack[count($stack)-1] ?? null;
					if ($currentTag === null) {
						return "";
					}
					$matches[1] = $currentTag;
				} else {
					$stack []= $matches[1];
				}
				preg_match("/font\s+color\s*=\s*[\"']?#(.{6})[\"']?/s", $matches[1], $colMatch);
				return $this->fgHexToAnsi($colMatch[1]);
			},
			$text
		);
		if ($this->chatBot->settingManager->getBool("console_bg_color")) {
			$text = $this->bgHexToAnsi("222222") . $text;
		}
		$text = str_replace("\r\n", "\n", $text);
		if ($clearEOL) {
			return str_replace("\n", "\e[K\n", $text) . "\e[K\e[0m";
		}
		return $text;
	}

	protected function fgHexToAnsi(string $hexColor): string {
		$codes = array_map("hexdec", str_split($hexColor, 2));
		return "\e[38;2;" . join(";", $codes) . "m";
	}

	protected function bgHexToAnsi(string $hexColor): string {
		$codes = array_map("hexdec", str_split($hexColor, 2));
		return "\e[48;2;" . join(";", $codes) . "m";
	}
}
