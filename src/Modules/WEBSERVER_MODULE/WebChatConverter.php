<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	ConfigFile,
	ModuleInstance,
	MessageHub,
	Routing\Source,
	SettingManager,
};

/**
 * @package Nadybot\Modules\WEBSERVER_MODULE
 */
#[NCA\Instance]
class WebChatConverter extends ModuleInstance {
	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public MessageHub $messageHub;

	public function convertMessage(string $msg): string {
		return $this->toXML($this->parseAOFormat($msg));
	}

	/**
	 * Add the color and display information to the path
	 * @param null|Source[] $path
	 * @return null|WebSource[]
	 */
	public function convertPath(?array $path=null): ?array {
		if (!isset($path)) {
			return null;
		}
		$result = [];
		$lastHop = null;
		foreach ($path as $hop) {
			$newHop = new WebSource($hop->type, $hop->name, $hop->label);
			foreach (get_object_vars($hop) as $key => $value) {
				$newHop->{$key} = $value;
			}
			$newHop->renderAs = $newHop->render($lastHop);
			$lastHop = $hop;
			$color = $this->messageHub->getHopColor($path, Source::WEB, $newHop, "tag_color");
			if (isset($color)) {
				$newHop->color = $color->tag_color ?? "";
			} else {
				$newHop->color = "";
			}
			$result []= $newHop;
		}
		return $result;
	}

	/**
	 * @param string[] $msgs
	 * @return string[]
	 */
	public function convertMessages(array $msgs): array {
		return array_map(
			[$this, "toXML"],
			$this->tryToUnbreakPopups(
				array_map([$this, "parseAOFormat"], $msgs)
			)
		);
	}

	/**
	 * Try to reverse the splitting of a large message into multiple ones
	 * @param AOMsg[] $msgs
	 * @return AOMsg[]
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
		if (preg_match('/#[0-9A-F]{6}/', $this->settingManager->getString($setting)??"", $matches)) {
			return $matches[0];
		}
		return "#000000";
	}

	public function formatMsg(string $message): string {
		$message = preg_replace("/^<header>\s*<header>/s", "<header>", $message);
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
			"<myname>" => $this->config->name,
			"<myguild>" => $this->config->orgName,
			"<tab>" => "<indent />",
			"<symbol>" => "",
			"<br>" => "<br />",
		];

		/** @var string[] */
		$stack = [];
		$message = preg_replace("/<\/font>/", "<end>", $message);
		$message = preg_replace_callback(
			"/<(end|" . join("|", array_keys($colors)) . "|font\s+color\s*=\s*[\"']?(#.{6})[\"']?)>/i",
			function(array $matches) use (&$stack, $colors): string {
				if ($matches[1] === "end") {
					if (empty($stack)) {
						return "";
					}
					// @phpstan-ignore-next-line
					return "</" . array_pop($stack) . ">";
				} elseif (preg_match("/font\s+color\s*=\s*[\"']?(#.{6})[\"']?/i", $matches[1], $colorMatch)) {
					$tag = $colorMatch[1];
				} else {
					$tag = $colors[strtolower($matches[1])]??null;
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
			// @phpstan-ignore-next-line
			$message .= "</" . array_pop($stack) . ">";
		}
		$message = preg_replace_callback(
			"/(\r?\n[-*][^\r\n]+){2,}/s",
			function (array $matches): string {
				$text = preg_replace("/(\r?\n)[-*]\s+([^\r\n]+)/s", "<li>$2</li>", $matches[0]);
				// @phpstan-ignore-next-line
				return "\n<ul>{$text}</ul>";
			},
			$message
		);
		$message = preg_replace_callback(
			"/^((?:    )+)/m",
			function(array $matches): string {
				return str_repeat("<indent />", (int)(strlen($matches[1])/4));
			},
			$message
		);
		$message = preg_replace("/\r?\n/", "<br />", $message);
		$message = preg_replace("/<a\s+href\s*=\s*['\"]?itemref:\/\/(\d+)\/(\d+)\/(\d+)['\"]?>(.*?)<\/a>/s", "<ao:item lowid=\"$1\" highid=\"$2\" ql=\"$3\">$4</ao:item>", $message);
		$message = preg_replace("/<a\s+href\s*=\s*['\"]?itemid:\/\/53019\/(\d+)['\"]?>(.*?)<\/a>/s", "<ao:nano id=\"$1\">$2</ao:nano>", $message);
		$message = preg_replace("/<a\s+href\s*=\s*['\"]?skillid:\/\/(\d+)['\"]?>(.*?)<\/a>/s", "<ao:skill id=\"$1\">$2</ao:skill>", $message);
		$message = preg_replace("/<a\s+href\s*=\s*['\"]?user:\/\/(.+?)['\"]?>(.*?)<\/a>/s", "<ao:user name=\"$1\">$2</ao:user>", $message);
		$message = preg_replace_callback(
			"/<a\s+href\s*=\s*(['\"])chatcmd:\/\/\/tell\s+<myname>\s+(.*?)\\1>(.*?)<\/a>/s",
			function(array $matches): string {
				return '<ao:command cmd="' . htmlentities($matches[2]) . "\">{$matches[3]}</ao:command>";
			},
			$message
		);
		$message = preg_replace("/<a\s+href=(['\"])chatcmd:\/\/\/start\s+(.*?)\\1>(.*?)<\/a>/s", "<a href=\"$2\">$3</a>", $message);
		$message = preg_replace("/<a\s+href=(['\"])chatcmd:\/\/\/(.*?)\\1>(.*?)<\/a>/s", "<ao:command cmd=\"$2\">$3</ao:command>", $message);
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

	/** Fix illegal HTML by closing/removing unclosed tags */
	public function fixUnclosedTags(string $message): string {
		$message = preg_replace("/<(\/?[a-z]+):/", "<$1___", $message);
		$xml = new \DOMDocument();
		@$xml->loadHTML('<?xml encoding="UTF-8">' . $message);
		if (($message = $xml->saveXML()) === false) {
			throw new Exception("Invalid XML data created");
		}
		$message = preg_replace("/^.+?<body>(.+)<\/body><\/html>$/si", "$1", $message);
		$message = preg_replace("/<([\/a-z]+)___/", "<$1:", $message);
		return $message;
	}

	public function parseAOFormat(string $message): AOMsg {
		$parts = [];
		$id = 0;
		$message = preg_replace_callback(
			"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1>(.*?)<\/a>/s",
			function (array $matches) use (&$parts, &$id): string {
				$parts["ao-" . ++$id] = $this->formatMsg(
					// @phpstan-ignore-next-line
					preg_replace(
						"/^<font.*?>(<\/font>|<end>)?/",
						"",
						preg_replace(
							"/^\s*(<font[^>]*>)?\s*<font[^>]*>(.+)<\/font>/m",
							"$1<header>$2<end>",
							str_replace(["&quot;", "&#39;"], ['"', "'"], $matches[2]),
							1
						)
					)
				);
				return "<popup ref=\"ao-$id\">" . $this->formatMsg($matches[3]) . "</popup>";
			},
			$message
		);

		return new AOMsg($this->formatMsg($message), (object)$parts);
	}

	public function toXML(AOMsg $msg): string {
		$data = "";
		if (count(get_object_vars($msg->popups))) {
			$data .= "<data>";
			foreach (get_object_vars($msg->popups) as $key => $value) {
				$data .= "<section id=\"{$key}\">" . $this->fixUnclosedTags($value) . "</section>";
			}
			$data .= "</data>";
		}
		$needNS = strstr($data, "<ao:") !== false || strstr($msg->message, "<ao:") !== false;
		$xml = "<?xml version='1.0' standalone='yes'?>".
			"<message" . ($needNS ? " xmlns:ao=\"ao:bot:common\"" : "") . ">".
			"<text>" . $this->fixUnclosedTags($msg->message) . "</text>".
			$data.
			"</message>";
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
