<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Protocol;

use Nadybot\Core\Registry;
use Nadybot\Core\Relaying\Character;
use Nadybot\Core\Relaying\RoutableEvent;
use Nadybot\Core\Relaying\RoutableMessage;
use Nadybot\Core\Relaying\Source;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

class GrcV2Protocol implements ProtocolInterface {

	public function render(RoutableEvent $event): ?string {
		if ($event->getType() !== RoutableEvent::TYPE_MESSAGE) {
			return null;
		}
		$path = $event->getPath();
		$msgColor = "";
		$hops = [];
		foreach ($path as $hop) {
			$tag = $hop->label ?: $hop->name;
			if ($hop->type === Source::ORG) {
				$hops []= "<relay_guild_tag_color>[{$tag}]</end>";
				$msgColor = "<relay_guild_color>";
			} elseif ($hop->type === Source::PRIV) {
				if (count($hops)) {
					$hops []= "<relay_guest_tag_color>[{$tag}]</end>";
					$msgColor = "<relay_guest_color>";
				} else {
					$hops []= "<relay_raidbot_tag_color>[{$tag}]</end>";
					$msgColor = "<relay_raidbot_color>";
				}
			} else {
				$hops []= "<relay_guest_tag_color>[{$tag}]</end>";
				$msgColor = "<relay_guest_color>";
			}
		}
		/** @var Util */
		$util = Registry::getInstance("util");
		$senderLink = "";
		$character = $event->getCharacter();
		if (isset($character) && $util->isValidSender($character->name)) {
			/** @var Text */
			$text = Registry::getInstance("text");
			$senderLink = $text->makeUserlink($character->name);
		} else {
			$msgColor = "<relay_bot_color>";
		}
		return "grc <v2>" . join(" ", $hops) . " {$senderLink}: {$msgColor}".
			$event->getData() . "</end>";
	}

	public function parse(string $data): ?RoutableEvent {
		if (!preg_match("/^.?grc <v2>(.+)/", $data, $matches)) {
			return null;
		}
		var_dump("Parsing $data");
		$data = $matches[1];
		$msg = new RoutableMessage($data);
		while (preg_match("/^<relay_(.+?)_tag_color>\[(.+?)\]<\/end>\s*(.*)/", $data, $matches)) {
			$type = ($matches[1] === "guild") ? Source::ORG : Source::PRIV;
			$msg->appendPath(new Source($type, $matches[2]));
			$data = $matches[3];
		}
		if (preg_match("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (preg_match("/([^ ]+):?\s*(.*)/", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		}
		$data = preg_replace("/^<relay_[a-z]+_color>(.*)<\/end>$/", '$1', $data);
		$msg->setData($data);
		return $msg;
	}
}
