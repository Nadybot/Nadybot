<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\{
	Attributes as NCA,
	Blob,
	MessageHub,
	Routing\Character,
	Routing\Events\Base,
	Routing\RoutableEvent,
	Routing\RoutableMessage,
	Routing\Source,
	Safe,
};

use Nadybot\Modules\RELAY_MODULE\{
	Relay,
	RelayMessage,
};

/**
 * This is the old BudaBot protocol.
 * It only supports relaying messages - no sharing of online lists
 * or any form of colorization beyond org or guest chat.
 */
#[NCA\RelayProtocol(name: 'grc')]
class GrcV1Protocol implements RelayProtocolInterface {
	protected static int $supportedFeatures = self::F_NONE;

	protected Relay $relay;

	#[NCA\Inject]
	private MessageHub $messageHub;

	/**
	 * @param string $command The command we send with each packet
	 * @param string $prefix  The prefix we send with each packet, e.g. "!" or ""
	 */
	public function __construct(
		#[NCA\Param] protected string $command='grc',
		#[NCA\Param] protected string $prefix=''
	) {
	}

	public function send(RoutableEvent $event): array {
		if ($event->getEvent() !== RoutableEvent::TYPE_MESSAGE) {
			if (!isset($event->data) || !($event->data instanceof Base) || !strlen($event->data->message??'')) {
				return [];
			}
			$event2 = clone $event;
			$event2->setData($event->data->message);
			$event = $event2;
		}
		$data = $event->getData();
		if (!is_string($data)) {
			return [];
		}
		$pages = (array)Blob::create($data)->render(formatMessage: true);
		return array_map(
			fn (string $page): string => "{$this->prefix}{$this->command} ".
				$this->messageHub->renderPath($event, '*', false).$page,
			$pages
		);
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		if (!count($message->packages)) {
			return null;
		}
		$data = array_shift($message->packages);
		$command = preg_quote($this->command, '/');
		if (!count($matches = Safe::pregMatch("/^.?{$command} (.+)/s", $data))) {
			return null;
		}
		$data = $matches[1];
		$message = new RoutableMessage($data);
		if (count($matches = Safe::pregMatch("/^\[(.*?)\]\s*(.*)/s", $data))) {
			if (strlen($matches[1])) {
				$message->appendPath(new Source(Source::ORG, $matches[1]));
			}
			$data = $matches[2];
		}
		if (count($matches = Safe::pregMatch("/^\[(.*?)\]\s*(.*)/s", $data))) {
			if (strlen($matches[1])) {
				$message->appendPath(new Source(Source::PRIV, $matches[1]));
			}
			$data = $matches[2];
		}
		if (count($matches = Safe::pregMatch("/^<a href=user:\/\/(.+?)>.*?<\/a>\s*:?\s*(.*)/s", $data))) {
			$message->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		} elseif (count($matches = Safe::pregMatch("/^([^ :]+):\s*(.*)/s", $data))) {
			$message->setCharacter(new Character($matches[1]));
			$data = $matches[2];
		}
		$message->setData(Blob::LITERAL . $data);
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
