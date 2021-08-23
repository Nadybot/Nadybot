<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

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
 * @RelayProtocol("grc")
 * @Description("This is the old BudaBot protocol.
 * 	It only supports relaying messages - no sharing of online lists.")
 */
class GrcV1Protocol implements RelayProtocolInterface {
	protected Relay $relay;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public MessageHub $messageHub;

	public function send(RoutableEvent $event): array {
		if ($event->getType() !== RoutableEvent::TYPE_MESSAGE) {
			return [];
		}
		return [
			"grc " . $this->messageHub->renderPath($event, false).
			$this->text->formatMessage($event->getData())
		];
	}

	public function receive(RelayMessage $msg): ?RoutableEvent {
		if (empty($msg->packages)) {
			return null;
		}
		$data = array_shift($msg->packages);
		if (!preg_match("/^.?grc (.+)/s", $data, $matches)) {
			return null;
		}
		$data = $matches[1];
		$msg = new RoutableMessage($data);
		if (preg_match("/^\[(.*?)\]\s*(.*)/s", $data, $matches)) {
			if (strlen($matches[1])) {
				$msg->appendPath(new Source(Source::ORG, $matches[1]));
			}
			$data = $matches[2];
		}
		if (preg_match("/^\[(.*?)\]\s*(.*)/s", $data, $matches)) {
			if (strlen($matches[1])) {
				$msg->appendPath(new Source(Source::PRIV, $matches[1]));
			}
			$data = $matches[2];
		}
		if (preg_match("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/s", $data, $matches)) {
			$msg->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (preg_match("/^([^ ]+):?\s*(.*)/s", $data, $matches)) {
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
