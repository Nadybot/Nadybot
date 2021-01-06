<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Registry;
use stdClass;

class AOMsg {
	public string $message;
	public object $popups;

	public function __construct(string $message, object $popups=null) {
		$this->message = $message;
		$this->popups = $popups ?? new stdClass();
	}

	/**
	 * @param string $msg
	 * @return self
	 */
	public static function fromMsg(string $msg): self {
		return static::tryToUnbreakPopups(
			array_map(["static", "parseOldFormat"], (array)$msg)
		)[0];
	}

	/**
	 * @param string[] $msg
	 * @return self[]
	 */
	public static function fromMsgs(array $msg): array {
		return static::tryToUnbreakPopups(
			array_map(["static", "parseOldFormat"], (array)$msg)
		);
	}

	/**
	 * Try to reverse the splitting of a large message into multiple ones
	 * @param self[] $msgs
	 * @return self[]
	 */
	public static function tryToUnbreakPopups(array $msgs): array {
		if (!preg_match("/<popup id=\"(\d)\">(.+?)<\/popup> \(Page <strong>1 \/ (\d+)<\/strong>\)/", $msgs[0]->message, $matches)) {
			return $msgs;
		}
		$msgs[0]->message = preg_replace(
			"/<popup id=\"".
			preg_quote($matches[1], "/").
			"\">(.+?)<\/popup> \(Page <strong>1 \/ (\d+)<\/strong>\)/",
			"<popup id=\"{$matches[1]}\">{$matches[2]}</popup>",
			$msgs[0]->message
		);
		$msgs[0]->popups->{$matches[1]} = preg_replace("/ \(Page 1 \/ \d+\)<\/h1>/", "</h1>", $msgs[0]->popups->{$matches[1]});
		for ($i = 1; $i < count($msgs); $i++) {
			if (preg_match(
				"/<popup id=\"(\d+)\">" .
					preg_quote($matches[2], "/") .
					"<\/popup> " .
					"\(Page <strong>\d+ \/ " .
					preg_quote($matches[3], "/") .
					"<\/strong>\)/",
				$msgs[$i]->message,
				$matches2
			)) {
				$expand = preg_replace("/^<h1>.+?<\/h1>(<br \/>){0,2}/", "", $msgs[$i]->popups->{$matches2[1]});
				$msgs[0]->popups->{$matches[1]} .= $expand;
			}
		}
		return [$msgs[0]];
	}

	public static function getColorFromSetting(string $setting): string {
		if (preg_match('/#[0-9A-F]{6}/', Registry::getInstance('settingManager')->getString($setting), $matches)) {
			return $matches[0];
		}
		return "#000000";
	}

	public static function formatMsg(string $message): string {
		$colors = [
			"header" => "<h1>",
			"header2" => "<h2>",
			"highlight" => "<strong>",
			"black" => "#000000",
			"white" => "#FFFFFF",
			"yellow" => "#FFFF00",
			"blue" => "#8CB5FF",
			"green" => "#00DE42",
			"red" => "#FF0000",
			"orange" => "#FCA712",
			"grey" => "#C3C3C3",
			"cyan" => "#00FFFF",
			"violet" => "#8F00FF",

			"neutral" => static::getColorFromSetting('default_neut_color'),
			"omni" => static::getColorFromSetting('default_omni_color'),
			"clan" => static::getColorFromSetting('default_clan_color'),
			"unknown" => static::getColorFromSetting('default_unknown_color'),
		];

		$symbols = [
			"<myname>" => Registry::getInstance('chatBot')->vars["name"],
			"<myguild>" => Registry::getInstance('chatBot')->vars["my_guild"],
			"<tab>" => "<tab />",
			"<symbol>" => "",
			"<br>" => "<br />",
		];

		$stack = [];
		$message = preg_replace("/<\/font>/", "<end>", $message);
		$message = preg_replace_callback(
			"/<(end|" . join("|", array_keys($colors)) . "|font\s+color\s*=\s*[\"']?(#.{6})[\"']?)>/i",
			function(array $matches) use (&$stack, $colors): string {
				if ($matches[1] === "end") {
					if (empty($stack)) {
						return "";
					}
					return "</" . array_pop($stack) . ">";
				} elseif (preg_match("/font\s+color\s*=\s*[\"']?(#.{6})[\"']?/i", $matches[1], $colorMatch)) {
					$tag = $colorMatch[1];
				} else {
					$tag = $colors[strtolower($matches[1])];
				}
				if ($tag === null) {
					return "";
				}
				if (substr($tag, 0, 1) === "#") {
					$stack []= "color";
					return "<color value=\"$tag\">";
				}
				$stack []= preg_replace("/[<>]/", "", $tag);
				return $tag;
			},
			$message
		);
		while (count($stack)) {
			$message .= "</" . array_pop($stack) . ">";
		}
		$message = preg_replace_callback(
			"/(\r?\n[-*][^\r\n]+){2,}/s",
			function (array $matches): string {
				$text = preg_replace("/(\r?\n)[-*]\s+([^\r\n]+)/s", "<li>$2</li>", $matches[0]);
				return "\n<ul>$text</ul>";
			},
			$message
		);
		$message = preg_replace("/\r?\n/", "<br />", $message);
		$message = preg_replace("/<a href=['\"]?itemref:\/\/(\d+)\/(\d+)\/(\d+)['\"]?>(.*?)<\/a>/s", "<item lowid=\"$1\" highid=\"$2\" ql=\"$3\">$4</item>", $message);
		$message = preg_replace("/<a href=['\"]?itemid:\/\/53019\/(\d+)['\"]?>(.*?)<\/a>/s", "<nano id=\"$1\">$2</nano>", $message);
		$message = preg_replace("/<a href=['\"]?skillid:\/\/(\d+)['\"]?>(.*?)<\/a>/s", "<skill id=\"$1\">$2</skill>", $message);
		$message = preg_replace_callback(
			"/<a href='chatcmd:\/\/\/tell\s+<myname>\s+(.*?)'>(.*?)<\/a>/s",
			function(array $matches): string {
				return '<command cmd="' . htmlentities($matches[1]) . "\">{$matches[2]}</command>";
			},
			$message
		);
		$message = preg_replace("/<a href='chatcmd:\/\/\/start\s+(.*?)'>(.*?)<\/a>/s", "<a href=\"$1\">$2</a>", $message);
		$message = preg_replace("/<a href='chatcmd:\/\/\/(.*?)'>(.*?)<\/a>/s", "<command cmd=\"$1\">$2</command>", $message);
		$message = str_ireplace(array_keys($symbols), array_values($symbols), $message);
		$message = preg_replace_callback(
			"/<img src=['\"]?tdb:\/\/id:GFX_GUI_ICON_PROFESSION_(\d+)['\"]?>/s",
			function (array $matches): string {
				return "<img prof=\"" . static::professionIdToName((int)$matches[1]) . "\" />";
			},
			$message
		);
		$message = preg_replace("/<img src=['\"]?rdb:\/\/(\d+)['\"]?>/s", "<img rdb=\"$1\" />", $message);
		$message = preg_replace("/<font color=[\"']?(#.{6})[\"']>/", "<color value=\"$1\">", $message);
		$message = preg_replace("/&(?!(?:[a-zA-Z]+|#\d+);)/", "&amp;", $message);
		$message = preg_replace("/<\/h(\d)>(<br\s*\/>){1,2}/", "</h$1>", $message);

		return $message;
	}

	public static function parseOldFormat(string $message): object {
		$parts = [];
		$id = 0;
		$message = preg_replace_callback(
			"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1>(.*?)<\/a>/s",
			function (array $matches) use (&$parts, &$id): string {
				$parts[++$id] = static::formatMsg(
					preg_replace(
						"/^<font.*?>(<end>)?/",
						"",
						str_replace(["&quot;", "&#39;"], ['"', "'"], $matches[2])
					)
				);
				return "<popup id=\"$id\">" . static::formatMsg($matches[3]) . "</popup>";
			},
			$message
		);

		return new static(static::formatMsg($message), (object)$parts);
	}

	public static function professionIdToName(int $id): string {
		$idToProf = [
			1  => "Soldier",
			2  => "Martial Artist",
			3  => "Engineer",
			4  => "Fixer",
			5  => "Agent",
			6  => "Adventurer",
			7  => "Trader",
			8  => "Bureaucrat",
			9  => "Enforcer",
			10 => "Doctor",
			11 => "Nano-Technician",
			12 => "Meta-Physicist",
			14 => "Keeper",
			15 => "Shade",
		];
		return $idToProf[$id] ?? "Unknown";
	}
}
