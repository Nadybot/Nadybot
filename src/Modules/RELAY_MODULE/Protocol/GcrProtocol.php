<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Protocol;

use Nadybot\Core\Registry;
use Nadybot\Core\Relaying\Character;
use Nadybot\Core\Relaying\RoutableEvent;
use Nadybot\Core\Relaying\RoutableMessage;
use Nadybot\Core\Relaying\Source;
use Nadybot\Core\Util;

class GcrProtocol implements ProtocolInterface {
	public function render(RoutableEvent $event): array {
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			return $this->renderMessage($event);
		}
		if ($event->getType() === RoutableEvent::TYPE_USER_STATE) {
			return $this->renderUserState($event);
		}
		return [];
	}

	public function renderMessage(RoutableMessage $event): array {
		$path = $event->getPath();
		$hops = [];
		foreach ($path as $hop) {
			$hops []= "##relay_channel##[" . ($hop->label ?: $hop->name) . "]##end##";
		}
		/** @var Util */
		$util = Registry::getInstance("util");
		$senderLink = "";
		$character = $event->getCharacter();
		if (isset($character) && $util->isValidSender($character->name)) {
			$senderLink = "##relay_name##{$character->name}:##end##";
		}
		return ["gcr " . join(" ", $hops) . " {$senderLink} " . $event->getData()];
	}

	public function renderUserState(RoutableMessage $event): array {
		$path = $event->getPath();
		$hops = [];
		foreach ($path as $hop) {
			$hops []= "##relay_channel##[" . ($hop->label ?: $hop->name) . "]##end##";
		}
		$character = $event->getCharacter();
		/** @var Util */
		$util = Registry::getInstance("util");
		if (!isset($character) || !$util->isValidSender($character->name)) {
			return null;
		}
		$type = $event->getData() ? "on" : "off";
		$joinMsg = "gcr " . join(" ", $hops).
			" ##logon_log{$type}_spam##{$character->name} logged {$type}##end##";
		return [$joinMsg];
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
