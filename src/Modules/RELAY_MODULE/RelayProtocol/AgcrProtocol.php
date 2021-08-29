<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Event;
use Nadybot\Core\MessageHub;
use Nadybot\Core\Routing\Character;
use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;

/**
 * @RelayProtocol("agcr")
 * @Description("This is the protocol that is used by the alliance of Rimor.
 * 	It does not supports sharing online lists and can only colorize
 * 	org and guest chat properly.")
 * @Param(name='command', description='The command we send with each packet', type='string', required=false)
 * @Param(name='prefix', description='The prefix we send with each packet, e.g. "!" or ""', type='string', required=false)
 */
class AgcrProtocol implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Text $text;

	protected string $command = "agcr";
	protected string $prefix = "!";

	public function __construct(string $command="agcr", string $prefix="!") {
		$this->command = $command;
		$this->prefix = $prefix;
	}

	public function send(RoutableEvent $event): array {
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			return $this->renderMessage($event);
		}
		if ($event->getType() === RoutableEvent::TYPE_EVENT) {
			if (!strlen($event->data->message??"")) {
				return [];
			}
			$event = clone $event;
			$event->setData($event->data->message);
			return $this->renderMessage($event);
		}
		return [];
	}

	public function renderMessage(RoutableEvent $event): array {
		return [
			$this->prefix.$this->command . " ".
				$this->messageHub->renderPath($event, false).
				$this->text->formatMessage($event->getData())
		];
	}

	public function receive(RelayMessage $msg): ?RoutableEvent {
		if (empty($msg->packages)) {
			return null;
		}
		$command = preg_quote($this->command, "/");
		$data = array_shift($msg->packages);
		if (!preg_match("/^.?{$command}\s+(.+)/s", $data, $matches)) {
			return null;
		}
		$data = $matches[1];
		$msg = new RoutableMessage($data);
		if (preg_match("/^\[(.+?)\]\s*(.*)/s", $data, $matches)) {
			$msg->appendPath(new Source(Source::ORG, $matches[1], $matches[1]));
			$data = $matches[2];
		}
		if (preg_match("/^\[(.+?)\]\s*(.*)/s", $data, $matches)) {
			$msg->appendPath(new Source(Source::PRIV, $matches[1], $matches[1]));
			$data = $matches[2];
		}
		if (preg_match("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/s", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (preg_match("/^([^ :]+):\s*(.*)/s", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		}
		$msg->setData($data);
		return $msg;
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
