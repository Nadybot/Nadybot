<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;

/**
 * @RelayProtocol("grcv2")
 * @Description("This is the old Nadybot protocol.
 * 	It enhances the old grc protocol by adding descriptions
 * 	in front of the tags and messages, so the client-side
 * 	can decide how to colorize them. However, it only supports
 * 	org, guest and raidbot chat.")
 * @Param(name='command', description='The command we send with each packet', type='string', required=false)
 * @Param(name='prefix', description='The prefix we send with each packet, e.g. "!" or ""', type='string', required=false)
 */
class GrcV2Protocol implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	protected string $command = "grc";
	protected string $prefix = "";

	public function __construct(string $command="grc", string $prefix="") {
		$this->command = $command;
		$this->prefix = $prefix;
	}

	public function send(RoutableEvent $event): array {
		if ($event->getType() !== RoutableEvent::TYPE_MESSAGE) {
			if (!strlen($event->data->message??"")) {
				return [];
			}
			$event = clone $event;
			$event->setData($event->data->message);
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
			"{$this->prefix}{$this->command} <v2>".
				join(" ", $hops) . " {$senderLink}: {$msgColor}".
				$this->text->formatMessage($event->getData()) . "</end>"
		];
	}

	public function receive(RelayMessage $msg): ?RoutableEvent {
		if (empty($msg->packages)) {
			return null;
		}
		$data = array_shift($msg->packages);
		$command = preg_quote($this->command, "/");
		if (!preg_match("/^.?{$command} <v2>(.+)/s", $data, $matches)) {
			return null;
		}
		$data = $matches[1];
		$msg = new RoutableMessage($data);
		while (preg_match("/^<relay_(.+?)_tag_color>\[(.*?)\]<\/end>\s*(.*)/s", $data, $matches)) {
			if (strlen($matches[2])) {
				$type = ($matches[1] === "guild") ? Source::ORG : Source::PRIV;
				$msg->appendPath(new Source($type, $matches[2], $matches[2]));
			}
			$data = $matches[3];
		}
		if (preg_match("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/s", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (preg_match("/^([^ :]+):\s*(.*)/s", $data, $matches)) {
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
