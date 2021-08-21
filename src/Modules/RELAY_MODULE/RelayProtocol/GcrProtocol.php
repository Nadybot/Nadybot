<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Event;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Util;
use Nadybot\Core\Text;
use Nadybot\Modules\RELAY_MODULE\Relay;

/**
 * @RelayProtocol("gcr")
 * @Description("This is the protocol that BeBot used to speak.
 * 	It supports a lot of stuff, including sharing online lists.")
 */
class GcrProtocol implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	public function send(RoutableEvent $event): array {
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			return $this->renderMessage($event);
		}
		if ($event->getType() === RoutableEvent::TYPE_EVENT) {
			/** @var Event $llEvent */
			$llEvent = $event->getData();
			if (in_array($llEvent->type, ["logon", "logoff"])) {
				return $this->renderUserState($event);
			}
		}
		return [];
	}

	public function renderMessage(RoutableEvent $event): array {
		$path = $event->getPath();
		$hops = [];
		$lastHop = null;
		foreach ($path as $hop) {
			$hopText = $hop->render($lastHop);
			if (isset($hopText)) {
				$hops []= "##relay_channel##[{$hopText}]##end##";
			}
		}
		$senderLink = "";
		$character = $event->getCharacter();
		if (isset($character) && $this->util->isValidSender($character->name)) {
			$senderLink = "##relay_name##{$character->name}:##end##";
		}
		return [
			"gcr " . join(" ", $hops) . " {$senderLink} ".
			$this->text->formatMessage($event->getData())
		];
	}

	public function renderUserState(RoutableEvent $event): array {
		$path = $event->getPath();
		$hops = [];
		$lastHop = null;
		foreach ($path as $hop) {
			$hops []= "##relay_channel##[" . $hop->render($lastHop) . "]##end##";
			$lastHop = $hop;
		}
		$character = $event->getCharacter();
		if (!isset($character) || !$this->util->isValidSender($character->name)) {
			return null;
		}
		$type = ($event->getData()->type === "logon") ? "on" : "off";
		$joinMsg = "gcr " . join(" ", $hops).
			" ##logon_log{$type}_spam##{$character->name} logged {$type}##end##";
		return [$joinMsg];
	}

	public function receive(string $data): ?RoutableEvent {
		if (!preg_match("/^.?gcr (.+)/", $data, $matches)) {
			return null;
		}
		$data = preg_replace("/##(relay_message|relay_channel)##/", "", $matches[1]);
		$data = preg_replace(
			"/##relay_name##([a-zA-Z0-9_-]+)(.*?)##end##/",
			"<a href=user://$1>$1</a>$2",
			$data
		);
		$data = preg_replace("/##logon_logo(n|ff)_spam##(.+)##end##$/", "$2", $data, -1, $count);
		if ($count > 0) {
			// @TODO: Send logon event
		}
		$data = preg_replace("/##relay_message##(.*)##end##$/", "$1", $data);
		$data = preg_replace("/##logon_logo(n|ff)_spam##(.+)##end##/", "$2", $data);
		$data = preg_replace("/##logon_ailevel##(.*?)##end##/", "<font color=#00DE42>$1</font>", $data);
		$data = preg_replace("/##logon_organization##(.*?)##end##/", "$1", $data);
		$data = preg_replace("/##(?:relay_mainname|logon_level)##(.+?)##end##/", "<highlight>$1<end>", $data);
		$msg = new RoutableMessage($data);
		if (preg_match("/^\[(.+?)\]\s*(.*)/", $data, $matches)) {
			$msg->appendPath(new Source(Source::ORG, $matches[1]));
			$data = $matches[2];
		}
		if (preg_match("/^\[(.+?)\]\s*(.*)/", $data, $matches)) {
			$msg->appendPath(new Source(Source::PRIV, $matches[1]));
			$data = $matches[2];
		}
		if (preg_match("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (preg_match("/^([^ ]+):?\s*(.*)/", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		}
		$msg->setData($this->replaceBeBotColors($data));
		return $msg;
	}

	/**
	 * Parse and replace BeBot-style color-codes (##red##) with their actual colors (<font>)
	 */
	public function replaceBeBotColors(string $text): string {
		$colors = [
			"aqua"         => "#00FFFF",
			"beige"        => "#FFE3A1",
			"black"        => "#000000",
			"blue"         => "#0000FF",
			"bluegray"     => "#8CB6FF",
			"bluesilver"   => "#9AD5D9",
			"brown"        => "#999926",
			"darkaqua"     => "#2299FF",
			"darklime"     => "#00A651",
			"darkorange"   => "#DF6718",
			"darkpink"     => "#FF0099",
			"forestgreen"  => "#66AA66",
			"fuchsia"      => "#FF00FF",
			"gold"         => "#CCAA44",
			"gray"         => "#808080",
			"green"        => "#008000",
			"lightbeige"   => "#FFFFC9",
			"lightfuchsia" => "#FF63FF",
			"lightgray"    => "#D9D9D2",
			"lightgreen"   => "#00DD44",
			"brightgreen"  => "#00F000",
			"lightmaroon"  => "#FF0040",
			"lightteal"    => "#15E0A0",
			"dullteal"     => "#30D2FF",
			"lightyellow"  => "#DEDE42",
			"lime"         => "#00FF00",
			"maroon"       => "#800000",
			"navy"         => "#000080",
			"olive"        => "#808000",
			"orange"       => "#FF7718",
			"pink"         => "#FF8CFC",
			"purple"       => "#800080",
			"red"          => "#FF0000",
			"redpink"      => "#FF61A6",
			"seablue"      => "#6699FF",
			"seagreen"     => "#66FF99",
			"silver"       => "#C0C0C0",
			"tan"          => "#DDDD44",
			"teal"         => "#008080",
			"white"        => "#FFFFFF",
			"yellow"       => "#FFFF00",
			"omni"         => "#00FFFF",
			"clan"         => "#FF9933",
			"neutral"      => "#FFFFFF",
		];
		$hlColor = $this->settingManager->getString('default_highlight_color');
		if (preg_match("/(#[A-F0-9]{6})/i", $hlColor, $matches)) {
			$colors["highlight"] = $matches[1];
		}

		$colorAliases = [
			"admin"          => "pink",
			"cash"           => "gold",
			"ccheader"       => "white",
			"cctext"         => "lightgray",
			"clan"           => "brightgreen",
			"emote"          => "darkpink",
			"error"          => "red",
			"feedback"       => "yellow",
			"gm"             => "redpink",
			"infoheader"     => "lightgreen",
			"infoheadline"   => "tan",
			"infotext"       => "forestgreen",
			"infotextbold"   => "white",
			"megotxp"        => "yellow",
			"meheald"        => "bluegray",
			"mehitbynano"    => "white",
			"mehitother"     => "lightgray",
			"menubar"        => "lightteal",
			"misc"           => "white",
			"monsterhitme"   => "red",
			"mypet"          => "orange",
			"newbie"         => "seagreen",
			"news"           => "brightgreen",
			"none"           => "fuchsia",
			"npcchat"        => "bluesilver",
			"npcdescription" => "yellow",
			"npcemote"       => "lightbeige",
			"npcooc"         => "lightbeige",
			"npcquestion"    => "lightgreen",
			"npcsystem"      => "red",
			"npctrade"       => "lightbeige",
			"otherhitbynano" => "bluesilver",
			"otherpet"       => "darkorange",
			"pgroup"         => "white",
			"playerhitme"    => "red",
			"seekingteam"    => "seablue",
			"shout"          => "lightbeige",
			"skillcolor"     => "beige",
			"system"         => "white",
			"team"           => "seagreen",
			"tell"           => "aqua",
			"tooltip"        => "black",
			"tower"          => "lightfuchsia",
			"vicinity"       => "lightyellow",
			"whisper"        => "dullteal",
		];
		$colorizedText = preg_replace_callback(
			"/##([a-zA-Z]+)##/",
			function (array $matches) use ($colorAliases, $colors): string {
				$color = strtolower($matches[1]);
				if (isset($colorAliases[$color])) {
					$color = $colorAliases[$color];
				}
				if (isset($colors[$color])) {
					return "<font color={$colors[$color]}>";
				} elseif ($color === "end") {
					return "</font>";
				}
				return $matches[0];
			},
			$text
		);
		return $colorizedText;
	}

	public function init(callable $callback): array {
		$callback();
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}
}
