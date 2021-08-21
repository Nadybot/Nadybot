<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\RELAY_MODULE\Relay;

/**
 * @RelayProtocol("grcv2")
 * @Description("This is the old Nadybot protocol.
 * 	It enhances the old grc protocol by adding descriptions
 * in front of the tags and messages, so the client-side
 * can decide how to colorize them.")
 */
class GrcV2Protocol implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	public function send(RoutableEvent $event): array {
		if ($event->getType() !== RoutableEvent::TYPE_MESSAGE) {
			return [];
		}
		$path = $event->getPath();
		$msgColor = "";
		$hops = [];
		$lastHop = null;
		foreach ($path as $hop) {
			$tag = $hop->render($lastHop);
			if (!isset($tag)) {
				continue;
			}
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
		$senderLink = "";
		$character = $event->getCharacter();
		if (isset($character) && $this->util->isValidSender($character->name)) {
			$senderLink = $this->text->makeUserlink($character->name);
		} else {
			$msgColor = "<relay_bot_color>";
		}
		return [
			"grc <v2>" . join(" ", $hops) . " {$senderLink}: {$msgColor}".
			$this->text->formatMessage($event->getData()) . "</end>"
		];
	}

	public function receive(string $data): ?RoutableEvent {
		if (!preg_match("/^.?grc <v2>(.+)/s", $data, $matches)) {
			return null;
		}
		$data = $matches[1];
		$msg = new RoutableMessage($data);
		while (preg_match("/^<relay_(.+?)_tag_color>\[(.*?)\]<\/end>\s*(.*)/s", $data, $matches)) {
			if (strlen($matches[2])) {
				$type = ($matches[1] === "guild") ? Source::ORG : Source::PRIV;
				$msg->appendPath(new Source($type, $matches[2]));
			}
			$data = $matches[3];
		}
		if (preg_match("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/s", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (preg_match("/([^ ]+):\s*(.*)/s", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		}
		if (preg_match("/^<relay_bot_color>/s", $data)) {
			$msg->char = null;
		}
		$data = preg_replace("/^<relay_[a-z]+_color>(.*)$/s", "$1", $data);
		$data = preg_replace("/<\/end>$/s", "", $data);
		$msg->setData(ltrim($data));
		return $msg;
	}

	public function setRelay(Relay $relay): void {
		$this->relay = $relay;
	}

	public function init(callable $callback): array {
		$callback();
		return [];
	}

	public function deinit(callable $callback): array {
		$callback();
		return [];
	}
}