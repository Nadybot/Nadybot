<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Nadybot;
use Nadybot\Core\SettingManager;

/**
 * @Instance
 * @package Nadybot\Modules\WEBSERVER_MODULE
 */
class WebChatConverter {

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public SettingManager $settingManager;

	/**
	 * @param string $msg
	 * @return self
	 */
	public function convertMessage(string $msg): string {
		return $this->toXML($this->parseAOFormat($msg));
	}

	/**
	 * @param string[] $msg
	 * @return string[]
	 */
	public function convertMessages(array $msgs): array {
		return $this->tryToUnbreakPopups(
			array_map([$this, "parseAOFormat"], $msgs)
		);
	}

	/**
	 * Try to reverse the splitting of a large message into multiple ones
	 * @param AOMsg[] $msgs
	 * @return string[]
	 */
	public function tryToUnbreakPopups(array $msgs): array {
		if (!preg_match("/<popup ref=\"(ao-\d)\">(.+?)<\/popup> \(Page <strong>1 \/ (\d+)<\/strong>\)/", $msgs[0]->message, $matches)) {
			return $msgs;
		}
		$msgs[0]->message = preg_replace(
			"/<popup ref=\"".
			preg_quote($matches[1], "/").
			"\">(.+?)<\/popup> \(Page <strong>1 \/ (\d+)<\/strong>\)/",
			"<popup ref=\"{$matches[1]}\">{$matches[2]}</popup>",
			$msgs[0]->message
		);
		$msgs[0]->popups->{$matches[1]} = preg_replace("/ \(Page 1 \/ \d+\)<\/h1>/", "</h1>", $msgs[0]->popups->{$matches[1]});
		for ($i = 1; $i < count($msgs); $i++) {
			if (preg_match(
				"/<popup ref=\"(ao-\d+)\">" .
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

	public function getColorFromSetting(string $setting): string {
		if (preg_match('/#[0-9A-F]{6}/', $this->settingManager->getString($setting), $matches)) {
			return $matches[0];
		}
		return "#000000";
	}

	public function formatMsg(string $message): string {
		$colors = [
			"header"    => "<h1>",
			"header2"   => "<h2>",
			"highlight" => "<strong>",
			"black"     => "#000000",
			"white"     => "#FFFFFF",
			"yellow"    => "#FFFF00",
			"blue"      => "#8CB5FF",
			"green"     => "#00DE42",
			"red"       => "#FF0000",
			"orange"    => "#FCA712",
			"grey"      => "#C3C3C3",
			"cyan"      => "#00FFFF",
			"violet"    => "#8F00FF",

			"neutral"   => $this->getColorFromSetting('default_neut_color'),
			"omni"      => $this->getColorFromSetting('default_omni_color'),
			"clan"      => $this->getColorFromSetting('default_clan_color'),
			"unknown"   => $this->getColorFromSetting('default_unknown_color'),
		];

		$symbols = [
			"<myname>" => $this->chatBot->vars["name"],
			"<myguild>" => $this->chatBot->vars["my_guild"],
			"<tab>" => "<indent />",
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
					return "<color fg=\"$tag\">";
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
		$message = preg_replace("/<a href=['\"]?itemref:\/\/(\d+)\/(\d+)\/(\d+)['\"]?>(.*?)<\/a>/s", "<ao:item lowid=\"$1\" highid=\"$2\" ql=\"$3\">$4</ao:item>", $message);
		$message = preg_replace("/<a href=['\"]?itemid:\/\/53019\/(\d+)['\"]?>(.*?)<\/a>/s", "<ao:nano id=\"$1\">$2</ao:nano>", $message);
		$message = preg_replace("/<a href=['\"]?skillid:\/\/(\d+)['\"]?>(.*?)<\/a>/s", "<ao:skill id=\"$1\">$2</ao:skill>", $message);
		$message = preg_replace("/<a href=['\"]?user:\/\/(.+?)['\"]?>(.*?)<\/a>/s", "<ao:user name=\"$1\">$2</ao:user>", $message);
		$message = preg_replace_callback(
			"/<a href='chatcmd:\/\/\/tell\s+<myname>\s+(.*?)'>(.*?)<\/a>/s",
			function(array $matches): string {
				return '<ao:command cmd="' . htmlentities($matches[1]) . "\">{$matches[2]}</ao:command>";
			},
			$message
		);
		$message = preg_replace("/<a href='chatcmd:\/\/\/start\s+(.*?)'>(.*?)<\/a>/s", "<a href=\"$1\">$2</a>", $message);
		$message = preg_replace("/<a href='chatcmd:\/\/\/(.*?)'>(.*?)<\/a>/s", "<ao:command cmd=\"$1\">$2</ao:command>", $message);
		$message = str_ireplace(array_keys($symbols), array_values($symbols), $message);
		$message = preg_replace_callback(
			"/<img src=['\"]?tdb:\/\/id:GFX_GUI_ICON_PROFESSION_(\d+)['\"]?>/s",
			function (array $matches): string {
				return "<ao:img prof=\"" . $this->professionIdToName((int)$matches[1]) . "\" />";
			},
			$message
		);
		$message = preg_replace("/<img\s+src\s*=\s*['\"]?rdb:\/\/(\d+)['\"]?>/s", "<ao:img rdb=\"$1\" />", $message);
		$message = preg_replace("/<font\s+color=[\"']?(#.{6})[\"']>/", "<color fg=\"$1\">", $message);
		$message = preg_replace("/&(?!(?:[a-zA-Z]+|#\d+);)/", "&amp;", $message);
		$message = preg_replace("/<\/h(\d)>(<br\s*\/>){1,2}/", "</h$1>", $message);

		return $message;
	}

	public function parseAOFormat(string $message): AOMsg {
		$parts = [];
		$id = 0;
		$message = preg_replace_callback(
			"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1>(.*?)<\/a>/s",
			function (array $matches) use (&$parts, &$id): string {
				$parts["ao-" . ++$id] = $this->formatMsg(
					preg_replace(
						"/^<font.*?>(<end>)?/",
						"",
						str_replace(["&quot;", "&#39;"], ['"', "'"], $matches[2])
					)
				);
				return "<popup ref=\"ao-$id\">" . $this->formatMsg($matches[3]) . "</popup>";
			},
			$message
		);

		return new AOMsg($this->formatMsg($message), (object)$parts);
	}

	public function toXML(AOMsg $msg): string {
		$xml = "<?xml version='1.0' standalone='yes'?>".
			"<message xmlns:ao=\"ao:bot:common\">".
			"<text>{$msg->message}</text>";
		if (count(get_object_vars($msg->popups))) {
			$xml .= "<data>";
			foreach ($msg->popups as $key => $value) {
				$xml .= "<section id=\"{$key}\">{$value}</section>";
			}
			$xml .= "</data>";
		}
		$xml .= "</message>";
		return $xml;
	}

	public function professionIdToName(int $id): string {
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
