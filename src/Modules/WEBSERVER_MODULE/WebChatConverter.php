<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use ErrorException;
use Exception;

use Nadybot\Core\Types\{HopColorType, Profession};
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	MessageHub,
	ModuleInstance,
	Routing\Source,
	Safe,
	SettingManager,
};

/**
 * @package Nadybot\Modules\WEBSERVER_MODULE
 */
#[NCA\Instance]
class WebChatConverter extends ModuleInstance {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function convertMessage(string $msg): string {
		return $this->toXML($this->parseAOFormat($msg));
	}

	/**
	 * Add the color and display information to the path
	 *
	 * @param ?list<Source> $path
	 *
	 * @return ?list<WebSource>
	 */
	public function convertPath(?array $path=null): ?array {
		if (!isset($path)) {
			return null;
		}
		$result = [];
		$lastHop = null;
		foreach ($path as $hop) {
			$newHop = new WebSource(type: $hop->type, name: $hop->name, label: $hop->label, color: '');
			$newHop = $newHop->updateWith($hop);
			$newHop->renderAs = $newHop->render($lastHop);
			$lastHop = $hop;
			$color = $this->messageHub->getHopColor($path, Source::WEB, $newHop, HopColorType::TagColor);
			if (isset($color)) {
				$newHop->color = $color->tag_color ?? '';
			} else {
				$newHop->color = '';
			}
			$result []= $newHop;
		}
		return $result;
	}

	/**
	 * @param list<string> $msgs
	 *
	 * @return list<string>
	 */
	public function convertMessages(array $msgs): array {
		return array_map(
			$this->toXML(...),
			array_map($this->parseAOFormat(...), $msgs)
		);
	}

	public function getColorFromSetting(string $setting): string {
		if (count($matches = Safe::pregMatch('/#[0-9A-F]{6}/', $this->settingManager->getString($setting)??''))) {
			return $matches[0];
		}
		return '#000000';
	}

	public function formatMsg(string $message): string {
		$message = Safe::pregReplace("/^<header>\s*<header>/s", '<header>', $message);
		$colors = [
			'header'    => '<h1>',
			'header2'   => '<h2>',
			'highlight' => '<strong>',
			'black'     => '#000000',
			'white'     => '#FFFFFF',
			'yellow'    => '#FFFF00',
			'blue'      => '#8CB5FF',
			'green'     => '#00DE42',
			'red'       => '#FF0000',
			'orange'    => '#FCA712',
			'grey'      => '#C3C3C3',
			'cyan'      => '#00FFFF',
			'violet'    => '#8F00FF',

			'on'        => $this->getColorFromSetting('default_enabled_color'),
			'off'       => $this->getColorFromSetting('default_disabled_color'),
			'neutral'   => $this->getColorFromSetting('default_neut_color'),
			'omni'      => $this->getColorFromSetting('default_omni_color'),
			'clan'      => $this->getColorFromSetting('default_clan_color'),
			'unknown'   => $this->getColorFromSetting('default_unknown_color'),
		];

		$symbols = [
			'<myname>' => $this->config->main->character,
			'<myguild>' => $this->config->general->orgName,
			'<tab>' => '<indent />',
			'<symbol>' => '',
			'<br>' => '<br />',
		];

		/** @var list<string> */
		$stack = [];
		$message = Safe::pregReplace("/<\/font>/", '<end>', $message);
		$message = Safe::pregReplaceCallback(
			'/<(end|' . implode('|', array_keys($colors)) . "|font\s+color\s*=\s*[\"']?(#.{6})[\"']?)>/i",
			static function (array $matches) use (&$stack, $colors): string {
				if ($matches[1] === 'end') {
					if (!count($stack)) {
						return '';
					}
					return '</' . array_pop($stack) . '>';
				} elseif (count($colorMatch = Safe::pregMatch("/font\s+color\s*=\s*[\"']?(#.{6})[\"']?/i", $matches[1]))) {
					$tag = $colorMatch[1];
				} else {
					$tag = $colors[strtolower($matches[1])]??null;
				}
				if ($tag === null) {
					return '';
				}
				if (substr($tag, 0, 1) === '#') {
					$stack []= 'color';
					return "<color fg=\"{$tag}\">";
				}

				/** @var string */
				$unTagged = Safe::pregReplace('/[<>]/', '', $tag);
				$stack []= $unTagged;
				return $tag;
			},
			$message
		);
		while (count($stack)) {
			$message .= '</' . array_pop($stack) . '>';
		}
		$message = Safe::pregReplaceCallback(
			"/(\r?\n[-*][^\r\n]+){2,}/s",
			static function (array $matches): string {
				$text = Safe::pregReplace("/(\r?\n)[-*]\s+([^\r\n]+)/s", '<li>$2</li>', $matches[0]);
				return "\n<ul>{$text}</ul>";
			},
			$message
		);
		$message = Safe::pregReplaceCallback(
			'/^((?:    )+)/m',
			static function (array $matches): string {
				return str_repeat('<indent />', (int)(strlen($matches[1])/4));
			},
			$message
		);
		$message = Safe::pregReplace("/\r?\n/", '<br />', $message);
		$message = Safe::pregReplace("/<a\s+href\s*=\s*['\"]?itemref:\/\/(\d+)\/(\d+)\/(\d+)['\"]?>(.*?)<\/a>/s", '<ao:item lowid="$1" highid="$2" ql="$3">$4</ao:item>', $message);
		$message = Safe::pregReplace("/<a\s+href\s*=\s*['\"]?itemid:\/\/53019\/(\d+)['\"]?>(.*?)<\/a>/s", '<ao:nano id="$1">$2</ao:nano>', $message);
		$message = Safe::pregReplace("/<a\s+href\s*=\s*['\"]?skillid:\/\/(\d+)['\"]?>(.*?)<\/a>/s", '<ao:skill id="$1">$2</ao:skill>', $message);
		$message = Safe::pregReplace("/<a\s+href\s*=\s*['\"]?user:\/\/(.+?)['\"]?>(.*?)<\/a>/s", '<ao:user name="$1">$2</ao:user>', $message);
		$message = Safe::pregReplaceCallback(
			"/<a\s+href\s*=\s*(['\"])chatcmd:\/\/\/tell\s+<myname>\s+(.*?)\\1>(.*?)<\/a>/s",
			static function (array $matches): string {
				return '<ao:command cmd="' . htmlentities($matches[2]) . "\">{$matches[3]}</ao:command>";
			},
			$message
		);
		$message = Safe::pregReplace("/<a\s+href=(['\"])chatcmd:\/\/\/start\s+(.*?)\\1>(.*?)<\/a>/s", '<a href="$2">$3</a>', $message);
		$message = Safe::pregReplace("/<a\s+href=(['\"])chatcmd:\/\/\/(.*?)\\1>(.*?)<\/a>/s", '<ao:command cmd="$2">$3</ao:command>', $message);
		$message = str_ireplace(array_keys($symbols), array_values($symbols), $message);
		$message = Safe::pregReplaceCallback(
			"/<img src=['\"]?tdb:\/\/id:GFX_GUI_ICON_PROFESSION_(\d+)['\"]?>/s",
			function (array $matches): string {
				return '<ao:img prof="' . $this->professionIdToName((int)$matches[1]) . '" />';
			},
			$message
		);
		$message = Safe::pregReplace("/<img\s+src\s*=\s*['\"]?rdb:\/\/(\d+)['\"]?>/s", '<ao:img rdb="$1" />', $message);
		$message = Safe::pregReplace("/<font\s+color=[\"']?(#.{6})[\"']>/", '<color fg="$1">', $message);
		$message = Safe::pregReplace("/&(?!(?:[a-zA-Z]+|#\d+);)/", '&amp;', $message);
		$message = Safe::pregReplace("/<\/h(\d)>(<br\s*\/>){1,2}/", '</h$1>', $message);

		return $message;
	}

	/** Fix illegal HTML by closing/removing unclosed tags */
	public function fixUnclosedTags(string $message): string {
		$message = Safe::pregReplace("/<(\/?[a-z]+):/", '<$1___', $message);
		$xml = new \DOMDocument();
		try {
			Safe::exceptionWrapper($xml->loadHTML(...), '<?xml encoding="UTF-8">' . $message, \LIBXML_BIGLINES|\LIBXML_PARSEHUGE|\LIBXML_NOWARNING|\LIBXML_NOERROR);
		} catch (ErrorException) {
		}
		if (($message = $xml->saveXML()) === false) {
			throw new Exception('Invalid XML data created');
		}
		$message = Safe::pregReplace("/^.+?<body>(.+)<\/body><\/html>$/si", '$1', $message);
		$message = Safe::pregReplace("/<([\/a-z]+)___/", '<$1:', $message);
		return $message;
	}

	public function parseAOFormat(string $message): AOMsg {
		$parts = [];
		$id = 0;
		$message = Safe::pregReplaceCallback(
			"/<a\s+href\s*=\s*([\"'])text:\/\/(.+?)\\1>(.*?)<\/a>/s",
			function (array $matches) use (&$parts, &$id): string {
				assert(is_string($matches[2]));
				$parts['ao-' . ++$id] = $this->formatMsg(
					Safe::pregReplace(
						"/^<font.*?>(<\/font>|<end>)?/",
						'',
						Safe::pregReplace(
							"/^\s*(<font[^>]*>)?\s*<font[^>]*>(.+)<\/font>/m",
							'$1<header>$2<end>',
							str_replace(['&quot;', '&#39;'], ['"', "'"], $matches[2]),
							1
						)
					)
				);
				return "<popup ref=\"ao-{$id}\">" . $this->formatMsg($matches[3]) . '</popup>';
			},
			$message
		);

		/** @var \stdClass */
		$partsObj = (object)$parts;

		return new AOMsg($this->formatMsg($message), $partsObj);
	}

	public function toXML(AOMsg $msg): string {
		$data = '';
		if (count(get_object_vars($msg->popups))) {
			$data .= '<data>';
			foreach (get_object_vars($msg->popups) as $key => $value) {
				$data .= "<section id=\"{$key}\">" . $this->fixUnclosedTags($value) . '</section>';
			}
			$data .= '</data>';
		}
		$needNS = strstr($data, '<ao:') !== false || strstr($msg->message, '<ao:') !== false;
		$xml = "<?xml version='1.0' standalone='yes'?>".
			'<message' . ($needNS ? ' xmlns:ao="ao:bot:common"' : '') . '>'.
			'<text>' . $this->fixUnclosedTags($msg->message) . '</text>'.
			$data.
			'</message>';
		return $xml;
	}

	public function professionIdToName(int $id): string {
		return (Profession::tryFromNumber($id) ?? Profession::Unknown)->value;
	}
}
