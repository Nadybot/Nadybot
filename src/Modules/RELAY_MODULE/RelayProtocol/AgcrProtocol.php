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
 * @RelayProtocol("agcr")
 * @Description("This is the protocol that is used by the alliance of Rimor.
 * 	It does not supports sharing online lists and can only colorize
 * 	org and guest chat properly.")
 * @Param(name='command', description='The command we send with each packet', type='string', required=false)
 * @Param(name='prefix', description='The prefix we send with each packet, e.g. "!" or ""', type='string', required=false)
 * @Param(name='force-single-hop', description='Instead of sending "[Org] [Guest]", force sending "[Org Guest]".
 *	This might be needed when old bots have problems parsing your sent messages,
 *	because they do not support guest chats.', type='boolean', required=false)
 * @Param(name='send-user-links', description='Send a clickable username for the sender.
 *	Disable when other bots cannot parse this and will render your messages wrong.', type='boolean', required=false)
 */
class AgcrProtocol implements RelayProtocolInterface {
	protected static int $supportedFeatures = self::F_NONE;

	protected Relay $relay;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Inject */
	public Text $text;

	protected string $command = "agcr";
	protected string $prefix = "!";
	protected bool $forceSingleHop = false;
	protected bool $sendUserLinks = true;

	public function __construct(string $command="agcr", string $prefix="!", bool $forceSingleHop=false, bool $sendUserLinks=true) {
		$this->command = $command;
		$this->prefix = $prefix;
		$this->forceSingleHop = $forceSingleHop;
		$this->sendUserLinks = $sendUserLinks;
	}

	public function send(RoutableEvent $event): array {
		if ($event->getType() === RoutableEvent::TYPE_MESSAGE) {
			return $this->renderMessage($event);
		}
		if ($event->getType() === RoutableEvent::TYPE_EVENT) {
			if (!is_object($event->data) || !strlen($event->data->message??"")) {
				return [];
			}
			$event2 = clone $event;
			$event2->setData($event->data->message);
			return $this->renderMessage($event2);
		}
		return [];
	}

	public function renderMessage(RoutableEvent $event): array {
		$path = $this->messageHub->renderPath($event, "relay", false, $this->sendUserLinks);
		if ($this->forceSingleHop) {
			$path = join(" ", explode("] [", $path));
		}
		return [
			$this->prefix.$this->command . " ".
				$path.
				$this->text->formatMessage($event->getData())
		];
	}

	public function receive(RelayMessage $message): ?RoutableEvent {
		if (empty($message->packages)) {
			return null;
		}
		$command = preg_quote($this->command, "/");
		$data = array_shift($message->packages);
		if (!preg_match("/^.?{$command}\s+(.+)/s", $data, $matches)) {
			return null;
		}
		$data = $matches[1];
		$message = new RoutableMessage($data);
		if (preg_match("/^\[(.+?)\]\s*(.*)/s", $data, $matches)) {
			$message->appendPath(new Source(Source::ORG, $matches[1], $matches[1]));
			$data = $matches[2];
		}
		if (preg_match("/^\[(.+?)\]\s*(.*)/s", $data, $matches)) {
			$message->appendPath(new Source(Source::PRIV, $matches[1], $matches[1]));
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
