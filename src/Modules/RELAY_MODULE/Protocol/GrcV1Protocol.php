<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Protocol;

use Nadybot\Core\Registry;
use Nadybot\Core\Relaying\Character;
use Nadybot\Core\Relaying\RoutableEvent;
use Nadybot\Core\Relaying\RoutableMessage;
use Nadybot\Core\Relaying\Source;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

class GrcV1Protocol implements ProtocolInterface {
	public function render(RoutableEvent $event): array {
		if ($event->getType() !== RoutableEvent::TYPE_MESSAGE) {
			return [];
		}
		$path = $event->getPath();
		$hops = [];
		foreach ($path as $hop) {
			$hops []= "[" . ($hop->label ?: $hop->name) . "]";
		}
		/** @var Util */
		$util = Registry::getInstance("util");
		$senderLink = "";
		$character = $event->getCharacter();
		if (isset($character) && $util->isValidSender($character->name)) {
			/** @var Text */
			$text = Registry::getInstance("text");
			$senderLink = $text->makeUserlink($character->name);
		}
		return ["grc " . join(" ", $hops) . " {$senderLink}: " . $event->getData()];
	}

	public function parse(string $data): ?RoutableEvent {
		if (!preg_match("/^.?grc (.+)/", $data, $matches)) {
			return null;
		}
		$data = $matches[1];
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
		$msg->setData($data);
		return $msg;
	}
}
