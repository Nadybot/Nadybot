<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use function Safe\preg_match;
use Nadybot\Core\{
	Attributes as NCA,
	MessageHub,
	Routing\Character,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Routing\RoutableMessage,
	Routing\Source,
	Text,
};

use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
};

#[
	NCA\RelayProtocol(
		name: "grc",
		description: "This is the old BudaBot protocol.\n".
			"It only supports relaying messages - no sharing of online lists\n".
			"or any form of colorization beyond org or guest chat."
	),
	NCA\Param(
		name: "command",
		type: "string",
		description: "The command we send with each packet",
		required: false
	),
	NCA\Param(
		name: "prefix",
		type: "string",
		description: "The prefix we send with each packet, e.g. \"!\" or \"\"",
		required: false
	)
]
class GrcV1Protocol implements RelayProtocolInterface {
	protected static int $supportedFeatures = self::F_NONE;

	protected Relay $relay;

	protected string $command = "grc";
	protected string $prefix = "";

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function __construct(string $command="grc", string $prefix="") {
		$this->command = $command;
		$this->prefix = $prefix;
	}

	public function send(RoutableEvent $event): array {
		if ($event->getType() !== RoutableEvent::TYPE_MESSAGE) {
			if (!isset($event->data) || !($event->data instanceof Base) || !strlen($event->data->message??"")) {
				return [];
			}
			$event2 = clone $event;
			$event2->setData($event->data->message);
			$event = $event2;
		}
		return [
			"{$this->prefix}{$this->command} " . $this->messageHub->renderPath($event, "*", false).
			$this->text->formatMessage($event->getData()),
		];
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		if (empty($message->packages)) {
			return null;
		}
		$data = array_shift($message->packages);
		$command = preg_quote($this->command, "/");
		if (!preg_match("/^.?{$command} (.+)/s", $data, $matches)) {
			return null;
		}
		$data = $matches[1];
		$message = new RoutableMessage($data);
		if (preg_match("/^\[(.*?)\]\s*(.*)/s", $data, $matches)) {
			if (strlen($matches[1])) {
				$message->appendPath(new Source(Source::ORG, $matches[1]));
			}
			$data = $matches[2];
		}
		if (preg_match("/^\[(.*?)\]\s*(.*)/s", $data, $matches)) {
			if (strlen($matches[1])) {
				$message->appendPath(new Source(Source::PRIV, $matches[1]));
			}
			$data = $matches[2];
		}
		if (preg_match("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/s", $data, $matches)) {
			$message->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (preg_match("/^([^ :]+):\s*(.*)/s", $data, $matches)) {
			$message->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		}
		$message->setData($data);
		return $message;
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

	public static function supportsFeature(int $feature): bool {
		return (static::$supportedFeatures & $feature) === $feature;
	}
}
