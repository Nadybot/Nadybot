<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Registry;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\RELAY_MODULE\Relay;

/**
 * @RelayProtocol("grc")
 * @Description("This is the old BudaBot protocol.
 * 	It only supports relaying messages - no sharing of online lists.")
 */
class GrcV1Protocol implements RelayProtocolInterface {
	protected Relay $relay;

	public function send(RoutableEvent $event): array {
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

	public function receive(string $data): ?RoutableEvent {
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

	public function init(?object $previous, callable $callback): void {
		$callback();
	}

	public function deinit(?object $previous, callable $callback): void {
		$callback();
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}
}
