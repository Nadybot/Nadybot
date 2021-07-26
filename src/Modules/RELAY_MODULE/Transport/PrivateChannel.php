<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;
use Nadybot\Modules\RELAY_MODULE\Relay;
use Nadybot\Modules\RELAY_MODULE\TransportProtocol\TransportProtocolInterface;

class PrivateChannel implements TransportInterface {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public EventManager $eventManager;

	protected Relay $relay;

	protected string $channel;

	public function __construct(string $channel) {
		$this->channel = $channel;
	}

	public function send(string $data): bool {
		return $this->chatBot->send_privgroup($this->channel, $data);
	}

	public function receiveMessage(AOChatEvent $event): void {
		if (strtolower($event->channel) !== strtolower($this->channel)) {
			return;
		}
		$this->relay->receive($event->message);
	}

	public function receiveInvite(AOChatEvent $event): void {
		if (strtolower($event->sender) !== strtolower($this->channel)) {
			return;
		}
		$this->chatBot->privategroup_join($event->sender);
	}

	public function init(): bool {
		$this->eventManager->subscribe("extpriv", [$this, "receiveMessage"]);
		$this->eventManager->subscribe("extJoinPrivRequest", [$this, "receiveInvite"]);
		return true;
	}
}
