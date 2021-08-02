<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Transport;

use Nadybot\Core\AOChatEvent;
use Nadybot\Core\EventManager;
use Nadybot\Core\Nadybot;
use Nadybot\Modules\RELAY_MODULE\Relay;

/**
 * @RelayTransport("private_channel")
 * @Description("This is the Anarchy Online private channel protocol.
 * 	You can use this to relay messages internally inside Anarchy Online.
 * 	Be aware though, that the delay is based on the size of the message
 * 	being sent.")
 * @Param(name='channel', description='The private channel to join', type='string', required=true)
 */
class PrivateChannel implements TransportInterface {
	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public EventManager $eventManager;

	protected Relay $relay;

	protected string $channel;

	protected $initCallback;

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
		if (isset($this->initCallback)) {
			$callback = $this->initCallback;
			unset($this->initCallback);
			$callback();
		}
	}

	public function init(?object $previous, callable $callback): void {
		$this->eventManager->subscribe("extpriv", [$this, "receiveMessage"]);
		$this->eventManager->subscribe("extJoinPrivRequest", [$this, "receiveInvite"]);
		$this->initCallback = $callback;
	}
}
